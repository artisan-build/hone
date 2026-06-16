<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RollupCommand extends Command
{
    protected $signature = 'hone:rollup';

    protected $description = 'Roll raw Hone events into daily aggregate metrics.';

    public function handle(): int
    {
        $groups = DB::connection('hone')->select(<<<'SQL'
            WITH raw_values AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    date(occurred_at AT TIME ZONE 'UTC') AS bucket_date,
                    -- Nightwatch reports `duration` in microseconds; divide by 1000 so aggregates
                    -- are stored in milliseconds. A literal `duration_ms` field is already in
                    -- milliseconds and used as-is.
                    COALESCE(
                        CASE WHEN jsonb_typeof(payload->'duration') = 'number' THEN (payload->>'duration')::double precision / 1000.0 END,
                        CASE WHEN jsonb_typeof(payload->'duration_ms') = 'number' THEN (payload->>'duration_ms')::double precision END
                    ) AS numeric_value
                FROM raw_events
            )
            SELECT
                app,
                record_type,
                normalized_key,
                deploy,
                bucket_date,
                count(*)::bigint AS sample_count,
                count(numeric_value)::bigint AS numeric_count,
                avg(numeric_value)::double precision AS avg_value,
                max(numeric_value)::double precision AS max_value,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY numeric_value) FILTER (WHERE numeric_value IS NOT NULL) AS p95_value,
                percentile_cont(0.99) WITHIN GROUP (ORDER BY numeric_value) FILTER (WHERE numeric_value IS NOT NULL) AS p99_value
            FROM raw_values
            GROUP BY app, record_type, normalized_key, deploy, bucket_date
        SQL);

        $timestamp = now();
        $aggregateRows = [];

        foreach ($groups as $group) {
            $baseRow = [
                'app' => $group->app,
                'record_type' => $group->record_type,
                'normalized_key' => $group->normalized_key,
                'deploy' => $group->deploy,
                'bucket_date' => $group->bucket_date,
                'sample_count' => (int) $group->sample_count,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            $aggregateRows[] = $baseRow + [
                'metric' => 'count',
                'value' => (float) $group->sample_count,
            ];

            if ((int) $group->numeric_count === 0) {
                continue;
            }

            foreach ([
                'avg' => $group->avg_value,
                'max' => $group->max_value,
                'p95' => $group->p95_value,
                'p99' => $group->p99_value,
            ] as $metric => $value) {
                $aggregateRows[] = $baseRow + [
                    'metric' => $metric,
                    'value' => (float) $value,
                ];
            }
        }

        if ($aggregateRows !== []) {
            $this->insertAggregateRows($aggregateRows);
        }

        $this->info(sprintf(
            'Processed %d groups; upserted %d aggregate rows.',
            count($groups),
            count($aggregateRows),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{app: string, record_type: string, normalized_key: string, deploy: ?string, bucket_date: string, sample_count: int, created_at: mixed, updated_at: mixed, metric: string, value: float}>  $aggregateRows
     */
    private function insertAggregateRows(array $aggregateRows): void
    {
        $bindings = [];
        $placeholders = [];

        foreach ($aggregateRows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            array_push(
                $bindings,
                $row['app'],
                $row['record_type'],
                $row['normalized_key'],
                $row['deploy'],
                $row['bucket_date'],
                $row['metric'],
                $row['value'],
                $row['sample_count'],
                $row['created_at'],
                $row['updated_at'],
            );
        }

        DB::connection('hone')->statement(<<<SQL
            INSERT INTO aggregates (app, record_type, normalized_key, deploy, bucket_date, metric, value, sample_count, created_at, updated_at)
            VALUES {$this->valuesClause($placeholders)}
            ON CONFLICT (app, record_type, normalized_key, deploy, bucket_date, metric)
            DO UPDATE SET value = EXCLUDED.value, sample_count = EXCLUDED.sample_count, updated_at = EXCLUDED.updated_at
            WHERE EXCLUDED.sample_count >= aggregates.sample_count
        SQL, $bindings);
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function valuesClause(array $placeholders): string
    {
        return implode(', ', $placeholders);
    }
}

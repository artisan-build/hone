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
                    date(occurred_at) AS bucket_date,
                    COALESCE((payload->>'duration')::double precision, (payload->>'duration_ms')::double precision) AS numeric_value
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
            DB::connection('hone')->table('aggregates')->upsert(
                values: $aggregateRows,
                uniqueBy: ['app', 'record_type', 'normalized_key', 'deploy', 'bucket_date', 'metric'],
                update: ['value', 'sample_count', 'updated_at'],
            );
        }

        $this->info(sprintf(
            'Processed %d groups; upserted %d aggregate rows.',
            count($groups),
            count($aggregateRows),
        ));

        return self::SUCCESS;
    }
}

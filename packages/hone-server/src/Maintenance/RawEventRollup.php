<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw events one UTC bucket day at a time.
 *
 * Each day is read through an `occurred_at` range predicate, so the work of one call is bounded by
 * that day's volume rather than by retained history, and the upsert is written in chunks, so no
 * statement approaches PostgreSQL's 65,535 bind-parameter limit however many groups a day holds.
 */
final class RawEventRollup
{
    /**
     * Ten bindings per row, so 10,000 parameters per statement.
     */
    public const UPSERT_CHUNK_ROWS = 1000;

    /**
     * @return array{groups: int, rows: int}
     */
    public function rollupDay(CarbonImmutable $day, DateTimeInterface $timestamp): array
    {
        $dayStart = $day->utc()->startOfDay();

        $groups = DB::connection('hone')->cursor(<<<'SQL'
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
                WHERE occurred_at >= ? AND occurred_at < ?
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
        SQL, [$dayStart->toIso8601String(), $dayStart->addDay()->toIso8601String()]);

        $groupCount = 0;
        $rowCount = 0;
        $pendingRows = [];

        foreach ($groups as $group) {
            $groupCount++;

            foreach ($this->aggregateRowsFor($group, $timestamp) as $row) {
                $pendingRows[] = $row;

                if (count($pendingRows) === self::UPSERT_CHUNK_ROWS) {
                    $this->upsertAggregateRows($pendingRows);
                    $rowCount += count($pendingRows);
                    $pendingRows = [];
                }
            }
        }

        if ($pendingRows !== []) {
            $this->upsertAggregateRows($pendingRows);
            $rowCount += count($pendingRows);
        }

        return ['groups' => $groupCount, 'rows' => $rowCount];
    }

    /**
     * @return list<array{app: string, record_type: string, normalized_key: string, deploy: ?string, bucket_date: string, sample_count: int, created_at: DateTimeInterface, updated_at: DateTimeInterface, metric: string, value: float}>
     */
    private function aggregateRowsFor(object $group, DateTimeInterface $timestamp): array
    {
        $baseRow = [
            'app' => (string) $group->app,
            'record_type' => (string) $group->record_type,
            'normalized_key' => (string) $group->normalized_key,
            'deploy' => $group->deploy === null ? null : (string) $group->deploy,
            'bucket_date' => (string) $group->bucket_date,
            'sample_count' => (int) $group->sample_count,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $rows = [$baseRow + [
            'metric' => 'count',
            'value' => (float) $group->sample_count,
        ]];

        if ((int) $group->numeric_count === 0) {
            return $rows;
        }

        foreach ([
            'avg' => $group->avg_value,
            'max' => $group->max_value,
            'p95' => $group->p95_value,
            'p99' => $group->p99_value,
        ] as $metric => $value) {
            $rows[] = $baseRow + [
                'metric' => $metric,
                'value' => (float) $value,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{app: string, record_type: string, normalized_key: string, deploy: ?string, bucket_date: string, sample_count: int, created_at: DateTimeInterface, updated_at: DateTimeInterface, metric: string, value: float}>  $aggregateRows
     */
    private function upsertAggregateRows(array $aggregateRows): void
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

        $values = implode(', ', $placeholders);

        DB::connection('hone')->statement(<<<SQL
            INSERT INTO aggregates (app, record_type, normalized_key, deploy, bucket_date, metric, value, sample_count, created_at, updated_at)
            VALUES {$values}
            ON CONFLICT (app, record_type, normalized_key, deploy, bucket_date, metric)
            DO UPDATE SET value = EXCLUDED.value, sample_count = EXCLUDED.sample_count, updated_at = EXCLUDED.updated_at
            WHERE EXCLUDED.sample_count >= aggregates.sample_count
        SQL, $bindings);
    }
}

<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class RawEventRollup
{
    /** Ten aggregate bindings or twelve hourly bindings per row stay well below PostgreSQL's limit. */
    public const UPSERT_CHUNK_ROWS = 1000;

    public function __construct(private readonly LogarithmicHistogram $histogram) {}

    /**
     * Store one bounded raw-event range as a mergeable hourly representation.
     *
     * @return array{groups: int}
     */
    public function rollupRange(
        CarbonImmutable $from,
        CarbonImmutable $until,
        DateTimeInterface $timestamp,
    ): array {
        $groups = DB::connection('hone')->cursor(<<<'SQL'
            WITH raw_values AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    date_trunc('hour', occurred_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC' AS bucket_hour,
                    COALESCE(
                        duration_ms,
                        CASE WHEN jsonb_typeof(payload->'duration') = 'number' THEN (payload->>'duration')::double precision / 1000.0 END,
                        CASE WHEN jsonb_typeof(payload->'duration_ms') = 'number' THEN (payload->>'duration_ms')::double precision END
                    ) AS numeric_value
                FROM raw_events
                WHERE occurred_at >= ? AND occurred_at < ?
            ), bucket_counts AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    bucket_hour,
                    CASE
                        WHEN numeric_value = 0 THEN 'z'
                        WHEN numeric_value > 0 THEN 'p:' || floor(ln(numeric_value) / ln(?::double precision))::bigint::text
                        WHEN numeric_value < 0 THEN 'n:' || floor(ln(abs(numeric_value)) / ln(?::double precision))::bigint::text
                    END AS histogram_bucket,
                    count(*)::bigint AS sample_count,
                    count(numeric_value)::bigint AS numeric_count,
                    sum(numeric_value)::double precision AS numeric_sum,
                    max(numeric_value)::double precision AS max_value
                FROM raw_values
                GROUP BY app, record_type, normalized_key, deploy, bucket_hour, histogram_bucket
            )
            SELECT
                app,
                record_type,
                normalized_key,
                deploy,
                bucket_hour,
                sum(sample_count)::bigint AS sample_count,
                sum(numeric_count)::bigint AS numeric_count,
                sum(numeric_sum)::double precision AS numeric_sum,
                max(max_value)::double precision AS max_value,
                coalesce(
                    jsonb_object_agg(histogram_bucket, numeric_count) FILTER (WHERE histogram_bucket IS NOT NULL),
                    '{}'::jsonb
                ) AS histogram
            FROM bucket_counts
            GROUP BY app, record_type, normalized_key, deploy, bucket_hour
        SQL, [
            $from->toIso8601String(),
            $until->toIso8601String(),
            LogarithmicHistogram::BASE,
            LogarithmicHistogram::BASE,
        ]);

        $groupCount = 0;
        $pendingRows = [];

        foreach ($groups as $group) {
            $groupCount++;
            $pendingRows[] = [
                'app' => (string) $group->app,
                'record_type' => (string) $group->record_type,
                'normalized_key' => (string) $group->normalized_key,
                'deploy' => $group->deploy === null ? null : (string) $group->deploy,
                'bucket_hour' => (string) $group->bucket_hour,
                'sample_count' => (int) $group->sample_count,
                'numeric_count' => (int) $group->numeric_count,
                'numeric_sum' => $group->numeric_sum === null ? null : (float) $group->numeric_sum,
                'max_value' => $group->max_value === null ? null : (float) $group->max_value,
                'histogram' => (string) $group->histogram,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($pendingRows) === self::UPSERT_CHUNK_ROWS) {
                $this->upsertHourlyRows($pendingRows);
                $pendingRows = [];
            }
        }

        if ($pendingRows !== []) {
            $this->upsertHourlyRows($pendingRows);
        }

        return ['groups' => $groupCount];
    }

    /**
     * Merge hourly representations into the outward-compatible daily aggregates.
     *
     * @return array{groups: int, rows: int}
     */
    public function rollupDaily(CarbonImmutable $day, DateTimeInterface $timestamp): array
    {
        $dayStart = $day->utc()->startOfDay();
        $groups = DB::connection('hone')->cursor(<<<'SQL'
            WITH hourly AS (
                SELECT *
                FROM hourly_aggregate_rollups
                WHERE bucket_hour >= ? AND bucket_hour < ?
            ), stats AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    sum(sample_count)::bigint AS sample_count,
                    sum(numeric_count)::bigint AS numeric_count,
                    sum(numeric_sum)::double precision AS numeric_sum,
                    max(max_value)::double precision AS max_value
                FROM hourly
                GROUP BY app, record_type, normalized_key, deploy
            ), histogram_bins AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    bucket.key AS histogram_bucket,
                    sum(bucket.value::bigint)::bigint AS bucket_count
                FROM hourly
                CROSS JOIN LATERAL jsonb_each_text(histogram) AS bucket
                GROUP BY app, record_type, normalized_key, deploy, bucket.key
            ), histograms AS (
                SELECT
                    app,
                    record_type,
                    normalized_key,
                    deploy,
                    jsonb_object_agg(histogram_bucket, bucket_count) AS histogram
                FROM histogram_bins
                GROUP BY app, record_type, normalized_key, deploy
            )
            SELECT stats.*, coalesce(histograms.histogram, '{}'::jsonb) AS histogram
            FROM stats
            LEFT JOIN histograms
                ON histograms.app = stats.app
                AND histograms.record_type = stats.record_type
                AND histograms.normalized_key = stats.normalized_key
                AND histograms.deploy IS NOT DISTINCT FROM stats.deploy
        SQL, [$dayStart->toIso8601String(), $dayStart->addDay()->toIso8601String()]);

        $groupCount = 0;
        $rowCount = 0;
        $pendingRows = [];

        foreach ($groups as $group) {
            $groupCount++;

            foreach ($this->aggregateRowsFor($group, $dayStart, $timestamp) as $row) {
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
     * Explicit historical rebuilds still work one UTC day at a time, but each raw read is one hour.
     *
     * @return array{groups: int, rows: int}
     */
    public function rollupDay(CarbonImmutable $day, DateTimeInterface $timestamp): array
    {
        $dayStart = $day->utc()->startOfDay();
        $groups = 0;

        for ($hour = $dayStart; $hour->lessThan($dayStart->addDay()); $hour = $hour->addHour()) {
            $groups += $this->rollupRange($hour, $hour->addHour(), $timestamp)['groups'];
        }

        $result = $this->rollupDaily($dayStart, $timestamp);

        return ['groups' => $groups, 'rows' => $result['rows']];
    }

    /**
     * @return list<array{app: string, record_type: string, normalized_key: string, deploy: ?string, bucket_date: string, sample_count: int, created_at: DateTimeInterface, updated_at: DateTimeInterface, metric: string, value: float}>
     */
    private function aggregateRowsFor(object $group, CarbonImmutable $day, DateTimeInterface $timestamp): array
    {
        $baseRow = [
            'app' => (string) $group->app,
            'record_type' => (string) $group->record_type,
            'normalized_key' => (string) $group->normalized_key,
            'deploy' => $group->deploy === null ? null : (string) $group->deploy,
            'bucket_date' => $day->toDateString(),
            'sample_count' => (int) $group->sample_count,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $rows = [$baseRow + ['metric' => 'count', 'value' => (float) $group->sample_count]];
        $numericCount = (int) $group->numeric_count;

        if ($numericCount === 0) {
            return $rows;
        }

        /** @var array<string, int> $histogram */
        $histogram = json_decode((string) $group->histogram, true, flags: JSON_THROW_ON_ERROR);
        $values = [
            'avg' => (float) $group->numeric_sum / $numericCount,
            'max' => (float) $group->max_value,
            'p95' => $this->histogram->percentile($histogram, 0.95),
            'p99' => $this->histogram->percentile($histogram, 0.99),
        ];

        foreach ($values as $metric => $value) {
            $rows[] = $baseRow + ['metric' => $metric, 'value' => (float) $value];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertHourlyRows(array $rows): void
    {
        $this->upsertRows(
            'hourly_aggregate_rollups',
            ['app', 'record_type', 'normalized_key', 'deploy', 'bucket_hour', 'sample_count', 'numeric_count', 'numeric_sum', 'max_value', 'histogram', 'created_at', 'updated_at'],
            $rows,
            'sample_count = EXCLUDED.sample_count, numeric_count = EXCLUDED.numeric_count, numeric_sum = EXCLUDED.numeric_sum, max_value = EXCLUDED.max_value, histogram = EXCLUDED.histogram, updated_at = EXCLUDED.updated_at',
            'EXCLUDED.sample_count >= hourly_aggregate_rollups.sample_count',
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function upsertAggregateRows(array $rows): void
    {
        $this->upsertRows(
            'aggregates',
            ['app', 'record_type', 'normalized_key', 'deploy', 'bucket_date', 'metric', 'value', 'sample_count', 'created_at', 'updated_at'],
            $rows,
            'value = EXCLUDED.value, sample_count = EXCLUDED.sample_count, updated_at = EXCLUDED.updated_at',
            'EXCLUDED.sample_count >= aggregates.sample_count',
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertRows(string $table, array $columns, array $rows, string $updates, string $condition): void
    {
        $bindings = [];
        $placeholders = [];

        foreach ($rows as $row) {
            $placeholders[] = '('.implode(', ', array_fill(0, count($columns), '?')).')';

            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        DB::connection('hone')->statement(sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (app, record_type, normalized_key, deploy, %s) DO UPDATE SET %s WHERE %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $table === 'aggregates' ? 'bucket_date, metric' : 'bucket_hour',
            $updates,
            $condition,
        ), $bindings);
    }
}

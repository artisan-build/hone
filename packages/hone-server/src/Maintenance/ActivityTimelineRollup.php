<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class ActivityTimelineRollup
{
    /**
     * @return array{buckets: int}
     */
    public function rollupDay(CarbonImmutable $day, DateTimeInterface $timestamp): array
    {
        $dayStart = $day->utc()->startOfDay();
        $result = DB::connection('hone')->selectOne(<<<'SQL'
            WITH claimed_events AS (
                UPDATE raw_events
                SET activity_bucketed_at = ?
                WHERE id IN (
                    SELECT id
                    FROM raw_events
                    WHERE occurred_at >= ?
                        AND occurred_at < ?
                        AND activity_bucketed_at IS NULL
                    FOR UPDATE SKIP LOCKED
                )
                RETURNING
                    app,
                    actor,
                    ran_queries,
                    floor(extract(epoch FROM occurred_at) / 60)::bigint * 60 AS bucket_epoch
            ), grouped_events AS (
                SELECT
                    app,
                    bucket_epoch,
                    count(*) FILTER (WHERE actor = 'human')::bigint AS human_requests,
                    count(*) FILTER (WHERE actor = 'guest')::bigint AS guest_requests,
                    count(*) FILTER (WHERE actor = 'guest' AND ran_queries IS TRUE)::bigint AS guest_requests_with_queries,
                    count(*) FILTER (WHERE actor = 'scheduled' AND ran_queries IS TRUE)::bigint AS scheduled_runs_with_queries,
                    count(*) FILTER (WHERE actor = 'scheduled' AND ran_queries IS FALSE)::bigint AS scheduled_runs_without_queries,
                    count(*) FILTER (WHERE actor = 'job' AND ran_queries IS TRUE)::bigint AS jobs_with_queries,
                    count(*) FILTER (WHERE actor = 'job' AND ran_queries IS FALSE)::bigint AS jobs_without_queries
                FROM claimed_events
                WHERE actor IN ('human', 'guest')
                    OR (actor IN ('scheduled', 'job') AND ran_queries IS NOT NULL)
                GROUP BY app, bucket_epoch
            ), upserted_buckets AS (
                INSERT INTO activity_buckets (
                    app,
                    bucket_minute,
                    human_requests,
                    guest_requests,
                    guest_requests_with_queries,
                    scheduled_runs_with_queries,
                    scheduled_runs_without_queries,
                    jobs_with_queries,
                    jobs_without_queries,
                    created_at,
                    updated_at
                )
                SELECT
                    app,
                    to_timestamp(bucket_epoch),
                    human_requests,
                    guest_requests,
                    guest_requests_with_queries,
                    scheduled_runs_with_queries,
                    scheduled_runs_without_queries,
                    jobs_with_queries,
                    jobs_without_queries,
                    ?,
                    ?
                FROM grouped_events
                ON CONFLICT (app, bucket_minute)
                DO UPDATE SET
                    human_requests = activity_buckets.human_requests + EXCLUDED.human_requests,
                    guest_requests = activity_buckets.guest_requests + EXCLUDED.guest_requests,
                    guest_requests_with_queries = activity_buckets.guest_requests_with_queries + EXCLUDED.guest_requests_with_queries,
                    scheduled_runs_with_queries = activity_buckets.scheduled_runs_with_queries + EXCLUDED.scheduled_runs_with_queries,
                    scheduled_runs_without_queries = activity_buckets.scheduled_runs_without_queries + EXCLUDED.scheduled_runs_without_queries,
                    jobs_with_queries = activity_buckets.jobs_with_queries + EXCLUDED.jobs_with_queries,
                    jobs_without_queries = activity_buckets.jobs_without_queries + EXCLUDED.jobs_without_queries,
                    updated_at = EXCLUDED.updated_at
                RETURNING 1
            )
            SELECT count(*)::bigint AS bucket_count FROM upserted_buckets
        SQL, [
            $timestamp,
            $dayStart->toIso8601String(),
            $dayStart->addDay()->toIso8601String(),
            $timestamp,
            $timestamp,
        ]);

        return ['buckets' => (int) ($result->bucket_count ?? 0)];
    }
}

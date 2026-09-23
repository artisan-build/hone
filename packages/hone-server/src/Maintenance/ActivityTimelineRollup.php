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
                    normalized_key,
                    response,
                    request_path,
                    request_host,
                    user_agent,
                    asn,
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
            ), grouped_background_events AS (
                SELECT
                    app,
                    bucket_epoch,
                    actor AS activity_type,
                    normalized_key AS identity,
                    count(*)::bigint AS runs_with_queries
                FROM claimed_events
                WHERE actor IN ('scheduled', 'job')
                    AND ran_queries IS TRUE
                GROUP BY app, bucket_epoch, actor, normalized_key
            ), grouped_request_events AS (
                SELECT
                    app,
                    bucket_epoch,
                    actor,
                    request_path AS path,
                    request_host AS host,
                    user_agent,
                    asn,
                    ran_queries,
                    CASE
                        WHEN jsonb_typeof(response->'sets_cookie') = 'boolean'
                        THEN (response->>'sets_cookie')::boolean
                    END AS sets_cookie,
                    CASE
                        WHEN jsonb_typeof(response->'cache_control') = 'string'
                        THEN response->>'cache_control'
                    END AS cache_control,
                    CASE
                        WHEN jsonb_typeof(response->'vary') = 'string'
                        THEN response->>'vary'
                    END AS vary,
                    count(*)::bigint AS hits
                FROM claimed_events
                WHERE actor IN ('human', 'guest')
                    AND request_path IS NOT NULL
                GROUP BY
                    app,
                    bucket_epoch,
                    actor,
                    request_path,
                    request_host,
                    user_agent,
                    asn,
                    ran_queries,
                    sets_cookie,
                    cache_control,
                    vary
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
            ), upserted_background_buckets AS (
                INSERT INTO background_activity_buckets (
                    app,
                    bucket_minute,
                    activity_type,
                    identity,
                    runs_with_queries,
                    created_at,
                    updated_at
                )
                SELECT
                    app,
                    to_timestamp(bucket_epoch),
                    activity_type,
                    identity,
                    runs_with_queries,
                    ?,
                    ?
                FROM grouped_background_events
                ON CONFLICT (app, bucket_minute, activity_type, identity)
                DO UPDATE SET
                    runs_with_queries = background_activity_buckets.runs_with_queries + EXCLUDED.runs_with_queries,
                    updated_at = EXCLUDED.updated_at
                RETURNING 1
            ), upserted_request_buckets AS (
                INSERT INTO request_activity_buckets (
                    app,
                    bucket_minute,
                    actor,
                    path,
                    host,
                    user_agent,
                    asn,
                    ran_queries,
                    sets_cookie,
                    cache_control,
                    vary,
                    hits,
                    dimensions_hash,
                    created_at,
                    updated_at
                )
                SELECT
                    app,
                    to_timestamp(bucket_epoch),
                    actor,
                    path,
                    host,
                    user_agent,
                    asn,
                    ran_queries,
                    sets_cookie,
                    cache_control,
                    vary,
                    hits,
                    md5(jsonb_build_array(
                        actor,
                        path,
                        host,
                        user_agent,
                        asn,
                        ran_queries,
                        sets_cookie,
                        cache_control,
                        vary
                    )::text),
                    ?,
                    ?
                FROM grouped_request_events
                ON CONFLICT (app, bucket_minute, dimensions_hash)
                DO UPDATE SET
                    hits = request_activity_buckets.hits + EXCLUDED.hits,
                    updated_at = EXCLUDED.updated_at
                RETURNING 1
            )
            SELECT
                (SELECT count(*)::bigint FROM upserted_buckets) AS bucket_count,
                (SELECT count(*)::bigint FROM upserted_background_buckets) AS background_bucket_count,
                (SELECT count(*)::bigint FROM upserted_request_buckets) AS request_bucket_count
        SQL, [
            $timestamp,
            $dayStart->toIso8601String(),
            $dayStart->addDay()->toIso8601String(),
            $timestamp,
            $timestamp,
            $timestamp,
            $timestamp,
            $timestamp,
            $timestamp,
        ]);

        return ['buckets' => (int) ($result->bucket_count ?? 0)];
    }
}

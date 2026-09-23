<?php

declare(strict_types=1);

return [
    /*
     | Ingest and MCP credentials are managed by artisan-build/built-for-cloud.
     | Hone keeps their installation-owned purposes distinct: hone.ingest maps
     | to consumption and hone.mcp maps to mcp.
     */
    'route_prefix' => env('HONE_ROUTE_PREFIX', ''),

    // Use an async connection such as redis for throughput; afterResponse keeps ingest unblocked even on sync.
    'queue' => env('HONE_QUEUE_CONNECTION'),
    'asn' => [
        // Path to an uncompressed local iptoasn.com TSV dataset. The dataset is not distributed with Hone.
        'iptoasn_path' => env('HONE_IPTOASN_PATH'),
    ],
    'mcp' => [
        'path' => env('HONE_MCP_PATH', '/mcp'),
    ],
    /*
     | Telemetry shares the application's own database unless one of these is set: with every
     | HONE_DB_* value unset, the `hone` connection resolves to the application's default
     | connection. Set any of them to isolate telemetry onto a separate Postgres database —
     | the values given here override the inherited ones, the rest carry across. No fallback
     | defaults: an unset value has to stay distinguishable from a configured one.
     */
    'database' => [
        'connection' => 'hone',
        'url' => env('HONE_DB_URL'),
        'host' => env('HONE_DB_HOST'),
        'port' => env('HONE_DB_PORT'),
        'database' => env('HONE_DB_DATABASE'),
        'username' => env('HONE_DB_USERNAME'),
        'password' => env('HONE_DB_PASSWORD'),
    ],
    'retention' => [
        'raw_hours' => (int) env('HONE_RETENTION_RAW_HOURS', 72),
        'aggregate_days' => (int) env('HONE_RETENTION_AGGREGATE_DAYS', 90),
        'sample_days' => (int) env('HONE_RETENTION_SAMPLE_DAYS', 7),
    ],
    /*
     | The hourly rollup re-reads whole UTC bucket days from (now - late_arrival_hours) through today,
     | so an event that arrives up to this many hours after it occurred is still aggregated. The
     | default is a conservative guess, NOT a measurement: size it from the observed
     | created_at - occurred_at distribution. Older ranges are rebuilt with `hone:backfill`.
     */
    'rollup' => [
        'late_arrival_hours' => (int) env('HONE_ROLLUP_LATE_ARRIVAL_HOURS', 24),
    ],
    'maintenance' => [
        // Releases the hone:maintain overlap lock if a run dies without clearing it.
        'overlap_lock_minutes' => (int) env('HONE_MAINTENANCE_OVERLAP_LOCK_MINUTES', 120),
    ],
    /*
     | Budgets for `hone:health`. Retention and aggregate freshness only alarm while ingest is
     | active (a raw event occurred within ingest_active_minutes).
     */
    'health' => [
        'ingest_active_minutes' => (int) env('HONE_HEALTH_INGEST_ACTIVE_MINUTES', 60),
        'maintenance_max_age_minutes' => (int) env('HONE_HEALTH_MAINTENANCE_MAX_AGE_MINUTES', 150),
        'retention_grace_hours' => (int) env('HONE_HEALTH_RETENTION_GRACE_HOURS', 24),
        'aggregate_max_age_hours' => (int) env('HONE_HEALTH_AGGREGATE_MAX_AGE_HOURS', 6),
    ],
];

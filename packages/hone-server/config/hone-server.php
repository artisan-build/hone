<?php

declare(strict_types=1);

return [
    /*
     | Ingest and MCP bearer tokens are managed by artisan-build/built-for-cloud:
     | per-app tokens live in the `api_tokens` table (issue them with
     | `php artisan token:create {name}`), plus an optional `FALLBACK_TOKEN`.
     */
    'route_prefix' => env('HONE_ROUTE_PREFIX', ''),

    // Use an async connection such as redis for throughput; afterResponse keeps ingest unblocked even on sync.
    'queue' => env('HONE_QUEUE_CONNECTION'),
    'mcp' => [
        'path' => env('HONE_MCP_PATH', '/mcp'),
        'local_name' => env('HONE_MCP_LOCAL_NAME', 'hone'),
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
];

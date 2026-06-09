<?php

declare(strict_types=1);

return [
    'database' => [
        'connection' => 'hone',
        'host' => env('HONE_DB_HOST', '127.0.0.1'),
        'port' => env('HONE_DB_PORT', 5432),
        'database' => env('HONE_DB_DATABASE', 'hone'),
        'username' => env('HONE_DB_USERNAME', 'hone'),
        'password' => env('HONE_DB_PASSWORD', ''),
    ],
    'retention' => [
        'raw_hours' => (int) env('HONE_RETENTION_RAW_HOURS', 72),
        'aggregate_days' => (int) env('HONE_RETENTION_AGGREGATE_DAYS', 90),
        'sample_days' => (int) env('HONE_RETENTION_SAMPLE_DAYS', 7),
    ],
];

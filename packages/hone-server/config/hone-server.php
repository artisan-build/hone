<?php

declare(strict_types=1);

$tokens = array_filter(array_map('trim', explode(',', (string) env('HONE_APP_TOKENS', ''))));

return [
    /*
     | HONE_APP_TOKENS format: "checkout=plaintexttoken,billing=another-token".
     | Plaintext tokens are hashed at config time and compared in constant time.
     */
    'apps' => array_values(array_filter(array_map(function (string $token): ?array {
        [$id, $plaintext] = array_pad(explode('=', $token, 2), 2, '');

        $id = trim($id);
        $plaintext = trim($plaintext);

        if ($id === '' || $plaintext === '') {
            return null;
        }

        return [
            'id' => $id,
            'token_hash' => hash('sha256', $plaintext),
        ];
    }, $tokens))),
    'route_prefix' => env('HONE_ROUTE_PREFIX', ''),
    'queue' => env('HONE_QUEUE_CONNECTION'),
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

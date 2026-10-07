<?php

declare(strict_types=1);

return [
    'url' => env('HONE_URL'),
    'token' => env('HONE_TOKEN'),
    'app' => env('HONE_APP', env('APP_NAME', 'laravel')),
    'deploy' => env('NIGHTWATCH_DEPLOY'),
    'buffer' => (int) env('HONE_BUFFER', 500),
    'flush_interval' => (float) env('HONE_FLUSH_INTERVAL', 60),
    'connect_timeout' => (float) env('HONE_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('HONE_TIMEOUT', 0.5),
    'console_connect_timeout' => (float) env('HONE_CONSOLE_CONNECT_TIMEOUT', env('HONE_CONNECT_TIMEOUT', 2.0)),
    'console_timeout' => (float) env('HONE_CONSOLE_TIMEOUT', env('HONE_TIMEOUT', 5.0)),
];

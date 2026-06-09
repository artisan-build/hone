<?php

declare(strict_types=1);

return [
    'url' => env('HONE_URL'),
    'token' => env('HONE_TOKEN'),
    'app' => env('HONE_APP', env('APP_NAME', 'laravel')),
    'deploy' => env('NIGHTWATCH_DEPLOY'),
    'buffer' => (int) env('HONE_BUFFER', 500),
    'connect_timeout' => (float) env('HONE_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('HONE_TIMEOUT', 0.5),
];

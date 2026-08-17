<?php

return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [],

    'providers' => [],

    'passwords' => [],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];

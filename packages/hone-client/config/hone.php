<?php

declare(strict_types=1);

return [
    'url' => env('HONE_URL'),
    'token' => env('HONE_TOKEN'),
    'discover_ingest' => env('HONE_DISCOVER_INGEST', true),
    'app' => env('HONE_APP', env('APP_NAME', 'laravel')),
    'deploy' => env('NIGHTWATCH_DEPLOY'),
    'buffer' => (int) env('HONE_BUFFER', 500),
    'flush_interval' => (float) env('HONE_FLUSH_INTERVAL', 60),

    /*
     * How long the ingest post may spend establishing the connection: DNS, the
     * TCP handshake and the TLS handshake, before a byte of the envelope moves.
     * 0.5s dropped batches outright whenever the edge was cold, so this is the
     * phase cURL error 28 was reported from. It sits inside the total budget
     * below, which cURL measures from the same instant, so raising it costs no
     * extra worker time in the worst case.
     */
    'connect_timeout' => (float) env('HONE_CONNECT_TIMEOUT', 1.5),

    /*
     * The total budget for one ingest post from a web request. It runs on the
     * terminate path, after the response has been sent, so it costs worker time
     * rather than response time. Observed Hone ingest latency is ~0.6-0.8s on
     * average with a p95 of ~1.7-2.2s, so 0.5s dropped most batches silently.
     *
     * A connect timeout above this one is clamped down to it, since the total
     * budget always wins in cURL.
     */
    'timeout' => (float) env('HONE_TIMEOUT', 2.5),
    'console_connect_timeout' => (float) env('HONE_CONSOLE_CONNECT_TIMEOUT', env('HONE_CONNECT_TIMEOUT', 2.0)),
    'console_timeout' => (float) env('HONE_CONSOLE_TIMEOUT', env('HONE_TIMEOUT', 5.0)),
];

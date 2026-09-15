<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Hone',
        'slug' => 'hone',
        'description' => 'Self-hosted, MCP-only LLM-facing telemetry for Laravel.',
        'icon' => 'https://raw.githubusercontent.com/artisan-build/hone/main/public/favicon.svg',
        'product_url' => 'https://scalpels.app/products/hone',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'hone.ingest' => 'consumption',
            'hone.mcp' => 'mcp',
        ],
    ],

    'ui' => [
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => ['hone.ingest', 'hone.mcp'],
    ],
];

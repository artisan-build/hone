<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Hone',
        'slug' => 'hone',
        'description' => 'Self-hosted, MCP-only LLM-facing telemetry for Laravel.',
        'icon' => 'https://scalpels.app/img/products/transparent/hone.png',
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

    'dashboard_prompts' => [
        [
            'title' => 'Which apps are reporting in?',
            'ask' => 'List every app reporting telemetry to Hone, when each last sent data, and flag any that look stale or are losing events.',
        ],
        [
            'title' => 'What got slower?',
            'ask' => 'Find my slowest request endpoints and queries over the last seven days, and check whether any of them regressed since the most recent deploy.',
        ],
        [
            'title' => 'What is breaking?',
            'ask' => 'List the exceptions with the most volume this week, newest first, and tell me which ones started after a deploy.',
        ],
        [
            'title' => 'Are background jobs healthy?',
            'ask' => 'Check queue throughput, slow jobs and scheduled task health, and tell me about anything failing, backing up or running long.',
        ],
    ],
];

<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleReKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\ConsoleRetireKeyCommand;
use ArtisanBuild\BuiltForCloud\Commands\CreateAdminCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialActivateCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialListCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialMintCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRevokeCommand;
use ArtisanBuild\BuiltForCloud\Commands\CredentialRotateCommand;
use ArtisanBuild\BuiltForCloud\Commands\HmacRewrapCommand;
use ArtisanBuild\BuiltForCloud\Commands\InstallOperatorCredentialCommand;
use ArtisanBuild\BuiltForCloud\Commands\OutboxDrainCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipMintClaimCommand;
use ArtisanBuild\BuiltForCloud\Commands\OwnershipRemintOwnerTokenCommand;
use ArtisanBuild\BuiltForCloud\Commands\SigningRootProvisionCommand;
use ArtisanBuild\BuiltForCloud\Commands\SubjectOffboardCommand;
use ArtisanBuild\BuiltForCloud\Commands\WarnExpiringCredentialsCommand;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Jobs\DeliverOwnershipWebhook;
use ArtisanBuild\BuiltForCloud\Testing\ConsumerConformance;
use ArtisanBuild\BuiltForCloud\Testing\ContractAssertions;
use ArtisanBuild\BuiltForCloud\Testing\FleetConformance;
use ArtisanBuild\HoneServer\Commands\MaintainCommand;
use ArtisanBuild\HoneServer\Commands\PruneCommand;
use ArtisanBuild\HoneServer\Commands\RollupCommand;
use ArtisanBuild\HoneServer\HoneServerServiceProvider;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\CacheStatsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\CommandStatsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\DeploysTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ExceptionsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\LogVolumeByLevelTool;
use ArtisanBuild\HoneServer\Mcp\Tools\MailVolumeTool;
use ArtisanBuild\HoneServer\Mcp\Tools\NotificationVolumeTool;
use ArtisanBuild\HoneServer\Mcp\Tools\QueryMetricTool;
use ArtisanBuild\HoneServer\Mcp\Tools\QueueThroughputTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RecordTypesTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RegressionCheckTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ScheduledTaskHealthTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowJobsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowOutgoingRequestsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowQueriesTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowRequestsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\TopUsersTool;
use Composer\InstalledVersions;

uses(ContractAssertions::class);

it('passes the package consumer conformance spec for the complete Hone consumer', function (): void {
    $packageRoot = InstalledVersions::getInstallPath('artisan-build/built-for-cloud');
    expect($packageRoot)->toBeString();

    $purposeMappings = [];
    foreach ((array) config('built-for-cloud.credentials.app_purposes', []) as $appPurpose => $purpose) {
        $purposeMappings[$appPurpose] = CredentialPurpose::from($purpose);
    }
    ksort($purposeMappings);

    $sourceRoots = [
        app_path(),
        base_path('bootstrap'),
        config_path(),
        database_path('migrations'),
        base_path('packages/hone-server/config'),
        base_path('packages/hone-server/database/migrations'),
        base_path('packages/hone-server/routes'),
        base_path('packages/hone-server/src'),
        base_path('routes'),
    ];
    sort($sourceRoots);

    $providerFiles = [
        app_path('Providers/AppServiceProvider.php'),
        $packageRoot.'/src/BuiltForCloudServiceProvider.php',
        base_path('packages/hone-server/src/HoneServerServiceProvider.php'),
    ];
    sort($providerFiles);

    $sorted = static function (array $members): array {
        sort($members);

        return $members;
    };

    $expected = [
        'runtime.meta' => [],
        'runtime.auth_schema' => [],
        'runtime.credential_listing' => [],
        'runtime.transport_parity' => [],
        'thin_host' => [],
        'credential_paths' => $sorted([
            'path:Basic|ArtisanBuild\BuiltForCloud\Auth\BasicAuthenticator',
            'path:Bearer|ArtisanBuild\BuiltForCloud\Auth\BearerAuthenticator',
            'path:HMAC|Http\Middleware\VerifyHmacSignature+Hmac\HmacVerifier',
            'path:MCP|Http\Middleware\AuthenticateMcp:store-bearer+v4.public',
            'path:asymmetric|Actions\MintCredential::mintEnrollment',
            'path:enrollment|OnboardingToken+POST:/bfc/claim,/bfc/onboarding/issue,/exchange,/verify',
            'path:system|SubjectType::Operator/Application/Installation+AuditActorType::CliOperator',
        ]),
        'credential_writers' => $sorted([
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSecretBearing',
            'ArtisanBuild\BuiltForCloud\Actions\MintCredential::mintSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithEnrollment',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithPendingSigningKey',
            'ArtisanBuild\BuiltForCloud\Actions\RotateCredential::replaceWithSecret',
            'ArtisanBuild\BuiltForCloud\OwnerCredentialMinter::mintFromHash',
            'ArtisanBuild\BuiltForCloud\UnifiedStoreCredentialMinter::mint',
        ]),
        'legacy_removal' => [],
        'system_authority' => $sorted([
            ConsoleReKeyCommand::class,
            ConsoleRetireKeyCommand::class,
            CreateAdminCommand::class,
            CredentialActivateCommand::class,
            CredentialListCommand::class,
            CredentialMintCommand::class,
            CredentialRevokeCommand::class,
            CredentialRotateCommand::class,
            HmacRewrapCommand::class,
            InstallOperatorCredentialCommand::class,
            OutboxDrainCommand::class,
            OwnershipMintClaimCommand::class,
            OwnershipRemintOwnerTokenCommand::class,
            SigningRootProvisionCommand::class,
            SubjectOffboardCommand::class,
            WarnExpiringCredentialsCommand::class,
            MaintainCommand::class,
            PruneCommand::class,
            RollupCommand::class,
            DeliverOwnershipWebhook::class,
            ProcessTelemetryBatch::class,
        ]),
        'no_signing_path' => [],
        'ui_config_reads' => $sorted([
            'ArtisanBuild\BuiltForCloud\AppPurposeRegistry|built-for-cloud.credentials.app_purposes|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\ManageTransitions|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.installation_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.managed_transitions|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.member_management|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.personal_credentials|1',
            'ArtisanBuild\BuiltForCloud\Http\Controllers\UiHome|built-for-cloud.ui.session_management|1',
            'ArtisanBuild\BuiltForCloud\LandingManifest|built-for-cloud.manifest|1',
            'ArtisanBuild\BuiltForCloud\LandingPageRegistrar|built-for-cloud.ui.landing_page|1',
            'ArtisanBuild\BuiltForCloud\UiCredentialPurposes|built-for-cloud.ui.credential_purposes|1',
        ]),
        'mcp_delegated' => $sorted([
            CacheStatsTool::class,
            CommandStatsTool::class,
            DeploysTool::class,
            ExceptionsTool::class,
            IngestFreshnessTool::class,
            ListAppsTool::class,
            LogVolumeByLevelTool::class,
            MailVolumeTool::class,
            NotificationVolumeTool::class,
            QueryMetricTool::class,
            QueueThroughputTool::class,
            RecordTypesTool::class,
            RegressionCheckTool::class,
            ScheduledTaskHealthTool::class,
            SlowJobsTool::class,
            SlowOutgoingRequestsTool::class,
            SlowQueriesTool::class,
            SlowRequestsTool::class,
            TopUsersTool::class,
        ]),
    ];

    $report = (new FleetConformance($this))->assert(new ConsumerConformance(
        consumer: 'hone',
        consumerRoot: base_path(),
        packageRoot: $packageRoot,
        sourceRoots: $sourceRoots,
        providerFiles: $providerFiles,
        runtimeAssertions: ['auth_schema', 'credential_listing', 'meta', 'transport_parity'],
        capabilities: ['mcp-delegated', 'mcp-serve'],
        purposeMappings: $purposeMappings,
        mcpServer: HoneMcpServer::class,
        expected: $expected,
    ));

    expect($report->passed)->toBeTrue()
        ->and(array_keys($report->families))->toBe(ConsumerConformance::FAMILIES)
        ->and($providerFiles)->toContain(
            app_path('Providers/'.class_basename(AppServiceProvider::class).'.php'),
            base_path('packages/hone-server/src/'.class_basename(HoneServerServiceProvider::class).'.php'),
        );
});

it('keeps supported credential configuration and guidance on fixed-purpose package commands', function (): void {
    $supportedFiles = [
        base_path('.claude/skills/provisioning-hone-on-cloud/SKILL.md'),
        base_path('.claude/skills/provisioning-hone-on-cloud/reference/resource-plan.md'),
        base_path('.env.example'),
        base_path('README.md'),
        base_path('packages/hone-client/docs/integrate/default.md'),
        base_path('packages/hone-client/skills/configuring-hone-client/SKILL.md'),
        base_path('packages/hone-server/README.md'),
        base_path('packages/hone-server/config/hone-server.php'),
    ];

    foreach ($supportedFiles as $path) {
        $contents = file_get_contents($path);

        expect($contents)
            ->toBeString()
            ->not->toContain(
                'FALLBACK_TOKEN',
                'HONE_APP_TOKENS',
                'HONE_MCP_TOKEN',
                'TokenRegistry',
                'ApiToken',
                'api_tokens',
                'token:create',
                'token:rotate',
                'token:revoke',
                'token:list',
                'token:usage',
            );

        preg_match_all('/php artisan bfc:credential:(?:mint|rotate|revoke)[^\n\x60]*/', $contents, $stateChangingCommands);

        foreach ($stateChangingCommands[0] as $command) {
            expect($command)->toContain('--local');
        }
    }
});

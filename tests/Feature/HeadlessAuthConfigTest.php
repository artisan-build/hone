<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Testing\ThinHostConformance;
use ArtisanBuild\BuiltForCloud\User;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

it('owns the exact Hone D-UI-3 application overlay', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');

    expect($appConfig)->toBe([
        'manifest' => [
            'name' => 'Hone',
            'slug' => 'hone',
            'description' => 'Self-hosted, MCP-only LLM-facing telemetry for Laravel.',
            'icon' => 'https://raw.githubusercontent.com/artisan-build/hone/main/public/favicon.svg',
            'product_url' => 'https://scalpels.app/products/hone',
        ],
        'credentials' => [
            'guard' => 'bfc',
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
    ])->and(app(AppPurposeRegistry::class)->purpose('hone.ingest'))->toBe(CredentialPurpose::Consumption)
        ->and(app(AppPurposeRegistry::class)->purpose('hone.mcp'))->toBe(CredentialPurpose::Mcp);
});

it('merges package defaults and owns the human auth foundation through the released provider', function (): void {
    $rootComposer = json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $serverComposer = json_decode((string) file_get_contents(base_path('packages/hone-server/composer.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(data_get($rootComposer, 'require.artisan-build/built-for-cloud'))->toBe('^0.11')
        ->and(data_get($serverComposer, 'require.artisan-build/built-for-cloud'))->toBe('^0.11')
        ->and(InstalledVersions::getPrettyVersion('artisan-build/built-for-cloud'))->toBe('v0.11.0')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.guards.web'))->toBe([
            'driver' => 'session',
            'provider' => 'users',
        ])->and(config('auth.providers.users'))->toBe([
            'driver' => 'eloquent',
            'model' => User::class,
        ])->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true)
        ->and(ThinHostConformance::configurationArtifacts((array) config('auth')))->toBe([])
        ->and(file_exists(config_path('auth.php')))->toBeFalse();
});

it('runs fresh package-owned migrations and resolves package users through the web guard', function (): void {
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite.database', ':memory:');

    expect(Artisan::call('migrate:fresh', [
        '--database' => 'sqlite',
        '--path' => 'vendor/artisan-build/built-for-cloud/database/migrations',
        '--force' => true,
    ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(Schema::hasColumn('credentials', 'purpose'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse()
        ->and(ThinHostConformance::sourceArtifacts(base_path()))->toBe([]);

    $user = createBuiltForCloudUser();
    $provider = Auth::guard('web')->getProvider();
    $retrieved = $provider->retrieveByCredentials(['email' => $user->email]);

    expect($retrieved)->toBeInstanceOf(User::class)
        ->and($provider->validateCredentials($retrieved, ['password' => 'test-password']))->toBeTrue();
});

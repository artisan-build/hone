<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Database\HoneConnectionConfig;
use ArtisanBuild\HoneServer\HoneServerServiceProvider;

$appDefault = [
    'driver' => 'pgsql',
    'url' => 'pgsql://app:secret@db.cloud.test:5432/app_production',
    'host' => 'db.cloud.test',
    'port' => 5432,
    'database' => 'app_production',
    'username' => 'app',
    'password' => 'secret',
    'charset' => 'utf8',
    'prefix' => '',
    'search_path' => 'public',
    'sslmode' => 'prefer',
];

$noOverrides = [
    'url' => null,
    'host' => null,
    'port' => null,
    'database' => null,
    'username' => null,
    'password' => null,
];

it('hands back the default connection untouched when no HONE_DB_* value is set', function () use ($appDefault, $noOverrides): void {
    expect(HoneConnectionConfig::resolve($appDefault, $noOverrides))->toBe($appDefault);
});

it('overrides only the HONE_DB_* values that are set and inherits the rest', function () use ($appDefault, $noOverrides): void {
    $resolved = HoneConnectionConfig::resolve($appDefault, [
        ...$noOverrides,
        'host' => 'telemetry.cloud.test',
        'database' => 'hone_telemetry',
    ]);

    expect($resolved)
        ->host->toBe('telemetry.cloud.test')
        ->database->toBe('hone_telemetry')
        ->username->toBe('app')
        ->password->toBe('secret')
        ->port->toBe(5432)
        ->sslmode->toBe('prefer')
        ->driver->toBe('pgsql');
});

it('drops an inherited connection url so it cannot override the telemetry database', function () use ($appDefault, $noOverrides): void {
    $resolved = HoneConnectionConfig::resolve($appDefault, [...$noOverrides, 'database' => 'hone_telemetry']);

    expect($resolved)->not->toHaveKey('url');
});

it('honors an explicit HONE_DB_URL override', function () use ($appDefault, $noOverrides): void {
    $resolved = HoneConnectionConfig::resolve($appDefault, [
        ...$noOverrides,
        'url' => 'pgsql://hone:hone@telemetry.cloud.test:5432/hone_telemetry',
    ]);

    expect($resolved['url'])->toBe('pgsql://hone:hone@telemetry.cloud.test:5432/hone_telemetry');
});

it('keeps a configured telemetry database on Postgres even when the app default is not', function () use ($noOverrides): void {
    $resolved = HoneConnectionConfig::resolve(
        ['driver' => 'sqlite', 'database' => database_path('database.sqlite')],
        [...$noOverrides, 'host' => 'telemetry.cloud.test', 'database' => 'hone_telemetry'],
    );

    expect($resolved)
        ->driver->toBe('pgsql')
        ->charset->toBe('utf8')
        ->search_path->toBe('public')
        ->timezone->toBe('UTC')
        ->prefix->toBe('');
});

it('resolves the hone connection to the app default connection when HONE_DB_* is unset', function () use ($appDefault, $noOverrides): void {
    config()->set('database.default', 'app_pgsql');
    config()->set('database.connections.app_pgsql', $appDefault);
    config()->set('database.connections.hone', null);
    config()->set('hone-server.database', ['connection' => 'hone'] + $noOverrides);

    (new HoneServerServiceProvider($this->app))->register();

    expect(config('database.connections.hone'))->toBe($appDefault);
});

it('leaves a hone connection the application defines itself alone', function () use ($appDefault, $noOverrides): void {
    $appDefined = [...$appDefault, 'database' => 'defined_by_the_app'];

    config()->set('database.default', 'app_pgsql');
    config()->set('database.connections.app_pgsql', $appDefault);
    config()->set('database.connections.hone', $appDefined);
    config()->set('hone-server.database', ['connection' => 'hone'] + $noOverrides);

    (new HoneServerServiceProvider($this->app))->register();

    expect(config('database.connections.hone'))->toBe($appDefined);
});

it('lets HONE_DB_* win over a hone connection the application defines itself', function () use ($appDefault, $noOverrides): void {
    config()->set('database.default', 'app_pgsql');
    config()->set('database.connections.app_pgsql', $appDefault);
    config()->set('database.connections.hone', [...$appDefault, 'database' => 'defined_by_the_app']);
    config()->set('hone-server.database', ['connection' => 'hone'] + [...$noOverrides, 'database' => 'hone_telemetry']);

    (new HoneServerServiceProvider($this->app))->register();

    expect(config('database.connections.hone.database'))->toBe('hone_telemetry');
});

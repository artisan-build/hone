<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Tests;

use ArtisanBuild\BfcClient\BfcClientServiceProvider;
use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Schema;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class DatabaseCacheSelfTelemetryTestCase extends Orchestra
{
    protected function setUp(): void
    {
        Env::getRepository()->set('NIGHTWATCH_FORCE_REQUEST', 'true');

        parent::setUp();

        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        $core = app(Core::class);
        $core->ingest->flush();
        $core->executionState->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Env::getRepository()->clear('NIGHTWATCH_FORCE_REQUEST');
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            NightwatchServiceProvider::class,
            BfcClientServiceProvider::class,
            HoneClientServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'database');
        $app['config']->set('cache.stores.database', [
            'driver' => 'database',
            'connection' => 'testing',
            'table' => 'cache',
            'lock_connection' => 'testing',
            'lock_table' => 'cache_locks',
        ]);
        $app['config']->set('hone.url', 'https://hone.test/ingest');
        $app['config']->set('hone.token', 'secret-token');
        $app['config']->set('hone.app', 'fixture-app');
        $app['config']->set('session.driver', 'array');
    }
}

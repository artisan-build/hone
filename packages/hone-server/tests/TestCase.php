<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Tests;

use ArtisanBuild\HoneServer\HoneServerServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @var list<string>
     */
    protected array $connectionsToTransact = ['hone'];

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            McpServiceProvider::class,
            HoneServerServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'hone');
        $app['config']->set('hone-server.database', [
            'connection' => 'hone',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'hone_server_test',
            'username' => 'root',
            'password' => '',
            'timezone' => 'UTC',
        ]);
        $app['config']->set('database.connections.hone', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'hone_server_test',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'timezone' => 'UTC',
        ]);
    }
}

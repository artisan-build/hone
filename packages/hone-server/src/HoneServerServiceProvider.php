<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer;

use Illuminate\Support\ServiceProvider;

final class HoneServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hone-server.php', 'hone-server');

        config()->set('database.connections.'.config('hone-server.database.connection'), [
            'driver' => 'pgsql',
            'host' => config('hone-server.database.host'),
            'port' => config('hone-server.database.port'),
            'database' => config('hone-server.database.database'),
            'username' => config('hone-server.database.username'),
            'password' => config('hone-server.database.password'),
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hone-server.php' => config_path('hone-server.php'),
        ], 'hone-server-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}

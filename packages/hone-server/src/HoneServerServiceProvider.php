<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer;

use ArtisanBuild\HoneServer\Commands\MaintainCommand;
use ArtisanBuild\HoneServer\Commands\PruneCommand;
use ArtisanBuild\HoneServer\Commands\RollupCommand;
use ArtisanBuild\HoneServer\Database\HoneConnectionConfig;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Middleware\AuthenticateHoneMcp;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

final class HoneServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hone-server.php', 'hone-server');

        $this->registerTelemetryConnection();
    }

    /**
     * Wire the telemetry connection Hone's raw events, samples, and aggregates live on.
     *
     * Precedence: explicit `HONE_DB_*` values win, then a `hone` connection the application
     * defines itself, and otherwise telemetry shares the application's default connection.
     */
    private function registerTelemetryConnection(): void
    {
        $name = (string) config('hone-server.database.connection', 'hone');

        /** @var array<string, mixed> $overrides */
        $overrides = [
            'url' => config('hone-server.database.url'),
            'host' => config('hone-server.database.host'),
            'port' => config('hone-server.database.port'),
            'database' => config('hone-server.database.database'),
            'username' => config('hone-server.database.username'),
            'password' => config('hone-server.database.password'),
        ];

        $configured = array_filter($overrides, fn (mixed $value): bool => $value !== null) !== [];

        if (! $configured && is_array(config('database.connections.'.$name))) {
            return;
        }

        /** @var array<string, mixed> $default */
        $default = config('database.connections.'.config('database.default'), []);

        $resolved = HoneConnectionConfig::resolve($default, $overrides);

        if ($resolved === []) {
            return;
        }

        config()->set('database.connections.'.$name, $resolved);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hone-server.php' => config_path('hone-server.php'),
        ], 'hone-server-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::prefix((string) config('hone-server.route_prefix', ''))
            ->group(__DIR__.'/../routes/hone-server.php');

        $this->app->booted(function (): void {
            Mcp::web((string) config('hone-server.mcp.path', '/mcp'), HoneMcpServer::class)
                ->middleware([AuthenticateHoneMcp::class]);

            Mcp::local((string) config('hone-server.mcp.local_name', 'hone'), HoneMcpServer::class);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                MaintainCommand::class,
                RollupCommand::class,
                PruneCommand::class,
            ]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                $schedule->command('hone:maintain')->hourly();
            });
        }
    }
}

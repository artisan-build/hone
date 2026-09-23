<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use ArtisanBuild\HoneClient\Commands\InstallCommand;
use ArtisanBuild\HoneClient\Commands\UpdateCommand;
use ArtisanBuild\HoneClient\Http\Middleware\CaptureResponseContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Psr\Log\LoggerInterface;

final class HoneClientServiceProvider extends ServiceProvider
{
    private static bool $insecureUrlWarningLogged = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/hone.php', 'hone');

        if ((blank(config('hone.url')) || blank(config('hone.token')))
            && blank(config('nightwatch.token'))) {
            config()->set('nightwatch.enabled', false);
        }

        $this->app->bind(HoneIngest::class, function (Application $app): HoneIngest {
            return new HoneIngest(
                url: (string) config('hone.url'),
                token: (string) config('hone.token'),
                app: (string) config('hone.app'),
                deploy: config('hone.deploy') === null ? null : (string) config('hone.deploy'),
                bufferLimit: (int) config('hone.buffer'),
                connectTimeout: (float) config('hone.connect_timeout'),
                timeout: (float) config('hone.timeout'),
                http: $app->make(Factory::class),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/hone.php' => config_path('hone.php'),
        ], 'hone-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                UpdateCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $url = config('hone.url');
            $token = config('hone.token');

            if (blank($url) && blank($token)) {
                return;
            }

            if (blank($url) || blank($token)) {
                Log::warning('Hone is half-configured: set both HONE_URL and HONE_TOKEN, or neither.');

                return;
            }

            $this->app->make(ContractsVersionNudge::class)->check();

            if (! $this->app->bound(Core::class)) {
                return;
            }

            $this->warnIfInsecureUrl((string) $url);

            /** @var Kernel $kernel */
            $kernel = $this->app->make(HttpKernelContract::class);
            $kernel->prependMiddleware(CaptureResponseContext::class);

            $core = $this->app->make(Core::class);
            $core->ingest = $this->app->make(HoneIngest::class);
        });
    }

    private function warnIfInsecureUrl(string $url): void
    {
        if (self::$insecureUrlWarningLogged || parse_url($url, PHP_URL_SCHEME) === 'https') {
            return;
        }

        self::$insecureUrlWarningLogged = true;

        Log::warning('Hone URL is not HTTPS; HONE_TOKEN will be sent over plaintext transport.');
    }
}

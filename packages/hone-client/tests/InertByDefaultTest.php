<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\HoneIngest;
use ArtisanBuild\HoneClient\Http\Middleware\CaptureResponseContext;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest;

it('does not connect to the Nightwatch agent when Hone and Nightwatch env are absent', function (): void {
    Route::get('/', fn () => response('ok'));

    $this->get('/')->assertSuccessful();

    $connection = @stream_socket_accept($this->nightwatchSocket, 0);

    if (is_resource($connection)) {
        fclose($connection);
    }

    $core = app(Core::class);
    $kernel = app(HttpKernelContract::class);

    assert($kernel instanceof Kernel);

    expect($connection)->toBeFalse()
        ->and($core->enabled())->toBeFalse()
        ->and($core->ingest)->toBeInstanceOf(Ingest::class)
        ->and($core->ingest)->not->toBeInstanceOf(HoneIngest::class)
        ->and($kernel->getGlobalMiddleware())->not->toContain(CaptureResponseContext::class)
        ->and($this->honeStoragePath.'/framework/hone')->not->toBeDirectory();
});

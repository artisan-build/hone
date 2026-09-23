<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\HoneIngest;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest;

it('keeps own-account nightwatch enabled from cached config and reaches its agent', function (): void {
    $this->bootWithCachedNightwatchConfiguration();

    expect(app()->configurationIsCached())->toBeTrue()
        ->and(Env::getRepository()->has('NIGHTWATCH_ENABLED'))->toBeFalse()
        ->and(Env::getRepository()->has('NIGHTWATCH_TOKEN'))->toBeFalse()
        ->and(config('hone.url'))->toBeNull()
        ->and(config('hone.token'))->toBeNull()
        ->and(config('nightwatch.token'))->toBe('nightwatch-token');

    Route::get('/', fn () => response('ok'));

    $this->get('/')->assertSuccessful();

    $connection = @stream_socket_accept($this->nightwatchSocket, 0);
    $connected = is_resource($connection);

    if ($connected) {
        fclose($connection);
    }

    $core = app(Core::class);

    expect($connected)->toBeTrue()
        ->and($core->enabled())->toBeTrue()
        ->and($core->ingest)->toBeInstanceOf(Ingest::class)
        ->and($core->ingest)->not->toBeInstanceOf(HoneIngest::class);
});

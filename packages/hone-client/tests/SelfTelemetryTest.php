<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\HoneIngest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;

it('does not record its own delivery while continuing to record app requests', function (): void {
    Http::fake();

    $core = app(Core::class);

    expect($core->ingest)->toBeInstanceOf(HoneIngest::class);

    Http::get('https://app-upstream.test/status')->throw();
    $core->ingest->digest();

    $deliveries = Http::recorded()
        ->filter(fn (array $exchange): bool => $exchange[0]->url() === 'https://hone.test/ingest')
        ->values();

    expect($deliveries)->toHaveCount(1)
        ->and(array_column($deliveries[0][0]['records'], 't'))->toBe(['outgoing-request'])
        ->and($deliveries[0][0]['records'][0]['host'])->toBe('app-upstream.test')
        ->and($core->executionState->outgoingRequests)->toBe(1);
});

it('restores recording after a delivery throws', function (): void {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://hone.test/ingest') {
            throw new RuntimeException('network down');
        }

        return Http::response();
    });

    $core = app(Core::class);
    $core->ingest->write(['t' => 'query']);

    $core->ingest->digest();

    expect($core->paused())->toBeFalse();

    Http::get('https://app-after-failure.test/status')->throw();

    expect($core->executionState->outgoingRequests)->toBe(1);
});

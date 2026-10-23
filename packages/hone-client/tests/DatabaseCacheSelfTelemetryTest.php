<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\HoneIngest;
use Illuminate\Support\Facades\Http;
use Laravel\Nightwatch\Core;

it('does not record its own database cache delivery work', function (): void {
    Http::fake();

    $core = app(Core::class);

    expect($core->ingest)->toBeInstanceOf(HoneIngest::class);

    $core->ingest->write(['t' => 'query', 'sql' => 'select 1']);
    $core->ingest->digest();

    expect($core->executionState->queries)->toBe(0)
        ->and($core->executionState->cacheEvents)->toBe(0);

    Http::assertSentCount(1);
});

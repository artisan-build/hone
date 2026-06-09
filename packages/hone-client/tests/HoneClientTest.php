<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use ArtisanBuild\HoneClient\HoneIngest;
use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Psr\Log\LoggerInterface;

function runHoneClientBootedRebind(): void
{
    (new HoneClientServiceProvider(app()))->boot();
}

function bindFakeNightwatchCore(mixed $ingest = null): object
{
    $core = new class($ingest)
    {
        public mixed $ingest;

        public function __construct(mixed $ingest)
        {
            $this->ingest = $ingest;
        }
    };

    app()->instance(Core::class, $core);

    return $core;
}

function honeIngest(int $bufferLimit = 500): HoneIngest
{
    return new HoneIngest(
        url: 'https://hone.test/ingest',
        token: 'secret-token',
        app: 'checkout',
        deploy: 'abc123',
        bufferLimit: $bufferLimit,
        connectTimeout: 0.5,
        timeout: 0.5,
        http: app(Factory::class),
        logger: app(LoggerInterface::class),
    );
}

it('rebinds nightwatch core ingest when url and token are configured', function (): void {
    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    bindFakeNightwatchCore();

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBeInstanceOf(HoneIngest::class);
});

it('stays inert when neither url nor token are configured', function (): void {
    config()->set('hone.url', null);
    config()->set('hone.token', null);

    $original = new stdClass;
    bindFakeNightwatchCore($original);

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBe($original)
        ->and(app(Core::class)->ingest)->not->toBeInstanceOf(HoneIngest::class);
});

it('stays inert and logs a warning when only url is configured', function (): void {
    Log::spy();

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', null);

    $original = new stdClass;
    bindFakeNightwatchCore($original);

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBe($original)
        ->and(app(Core::class)->ingest)->not->toBeInstanceOf(HoneIngest::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Hone is half-configured: set both HONE_URL and HONE_TOKEN, or neither.');
});

it('posts an envelope with buffered records and bearer token on flush', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'sql' => 'select 1']);
    $ingest->write(['t' => 'request', 'route' => 'GET /']);

    $ingest->flush();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://hone.test/ingest'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request['envelope_version'] === Envelope::VERSION
            && $request['app'] === 'checkout'
            && $request['deploy'] === 'abc123'
            && count($request['records']) === 2
            && $request['records'][0]['t'] === 'query';
    });
});

it('fails open when the http client throws', function (): void {
    Http::fake(fn (): never => throw new RuntimeException('network down'));

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->flush();

    expect(true)->toBeTrue();
});

it('fails open when the hone server returns an error', function (): void {
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 500),
    ]);

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->flush();

    expect(true)->toBeTrue();
});

it('keeps only the most recent records when the buffer exceeds its limit', function (): void {
    Http::fake();

    $ingest = honeIngest(bufferLimit: 3);

    foreach (range(1, 5) as $index) {
        $ingest->write(['t' => 'query', 'index' => $index]);
    }

    $ingest->flush();

    Http::assertSent(function (Request $request): bool {
        return count($request['records']) === 3
            && array_column($request['records'], 'index') === [3, 4, 5];
    });
});

it('clears the buffer after sending', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->flush();
    $ingest->flush();

    Http::assertSentCount(1);
});

it('sends immediately from write now', function (): void {
    Http::fake();

    honeIngest()->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
});

it('implements the nightwatch ingest contract', function (): void {
    expect(honeIngest())->toBeInstanceOf(Ingest::class);
});

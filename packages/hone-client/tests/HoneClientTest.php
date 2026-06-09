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
use Psr\Log\AbstractLogger;
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

function honeIngest(
    int $bufferLimit = 500,
    float $connectTimeout = 0.5,
    float $timeout = 0.5,
    ?LoggerInterface $logger = null,
): HoneIngest {
    return new HoneIngest(
        url: 'https://hone.test/ingest',
        token: 'secret-token',
        app: 'checkout',
        deploy: 'abc123',
        bufferLimit: $bufferLimit,
        connectTimeout: $connectTimeout,
        timeout: $timeout,
        http: app(Factory::class),
        logger: $logger ?? app(LoggerInterface::class),
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

it('logs a warning when configured with an insecure hone url', function (): void {
    Log::spy();

    config()->set('hone.url', 'http://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    bindFakeNightwatchCore();

    runHoneClientBootedRebind();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Hone URL is not HTTPS; HONE_TOKEN will be sent over plaintext transport.');
});

it('posts an envelope with buffered records and bearer token on digest', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'sql' => 'select 1']);
    $ingest->write(['t' => 'request', 'route' => 'GET /']);

    $ingest->digest();

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
    $attempts = 0;
    Http::fake(function () use (&$attempts): never {
        $attempts++;

        throw new RuntimeException('network down');
    });

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->digest();
    $ingest->digest();

    expect($attempts)->toBe(1);
});

it('fails open when the hone server returns an error', function (): void {
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 500),
    ]);

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->digest();
    $ingest->digest();

    Http::assertSentCount(1);
});

it('fails open even when the logger throws', function (): void {
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 500),
    ]);

    $logger = new class extends AbstractLogger
    {
        /**
         * @param  array<string, mixed>  $context
         */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            throw new RuntimeException('logger down');
        }
    };

    $ingest = honeIngest(logger: $logger);
    $ingest->write(['t' => 'query']);

    $ingest->digest();

    expect(true)->toBeTrue();
});

it('keeps only the most recent records when the buffer exceeds its limit', function (): void {
    Http::fake();

    $ingest = honeIngest(bufferLimit: 3);

    foreach (range(1, 5) as $index) {
        $ingest->write(['t' => 'query', 'index' => $index]);
    }

    $ingest->digest();

    Http::assertSent(function (Request $request): bool {
        return count($request['records']) === 3
            && array_column($request['records'], 'index') === [3, 4, 5];
    });
});

it('clears the buffer after digest sends', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->digest();
    $ingest->digest();

    Http::assertSentCount(1);
});

it('clears the buffer without sending when flushed alone', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->flush();
    $ingest->digest();

    Http::assertNothingSent();
});

it('sends once for the end of request digest then flush sequence', function (): void {
    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query']);

    $ingest->digest();
    $ingest->flush();

    Http::assertSentCount(1);

    Http::assertSent(function (Request $request): bool {
        return count($request['records']) === 1
            && $request['records'][0]['t'] === 'query';
    });
});

it('sends immediately from write now', function (): void {
    Http::fake();

    honeIngest()->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
});

it('does not digest when the buffer reaches its limit during write', function (): void {
    Http::fake();

    $ingest = honeIngest(bufferLimit: 1);
    $ingest->write(['t' => 'query', 'index' => 1]);
    $ingest->write(['t' => 'query', 'index' => 2]);

    Http::assertNothingSent();

    $ingest->digest();

    Http::assertSent(function (Request $request): bool {
        return count($request['records']) === 1
            && $request['records'][0]['index'] === 2;
    });
});

it('clamps zero and negative timeouts to a safe positive floor', function (): void {
    $sentOptions = null;

    Http::fake(function (Request $request, array $options) use (&$sentOptions) {
        $sentOptions = $options;

        return Http::response();
    });

    $ingest = honeIngest(connectTimeout: 0, timeout: -1);
    $ingest->write(['t' => 'query']);

    $ingest->digest();

    expect($sentOptions['connect_timeout'])->toBe(0.05)
        ->and($sentOptions['timeout'])->toBe(0.05);
});

it('implements the nightwatch ingest contract', function (): void {
    expect(honeIngest())->toBeInstanceOf(Ingest::class);
});

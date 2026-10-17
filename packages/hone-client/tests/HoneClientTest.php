<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\ContractsVersionNudge;
use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use ArtisanBuild\HoneClient\HoneEndpoint;
use ArtisanBuild\HoneClient\HoneIngest;
use ArtisanBuild\HoneClient\Http\Middleware\CaptureResponseContext;
use ArtisanBuild\HoneClient\IngestDiscovery;
use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Hooks\WorkerLifecycleListener;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

function runHoneClientBootedRebind(): void
{
    (new HoneClientServiceProvider(app()))->boot();
}

function useHoneClientTempApp(string $env = '', ?string $composer = null): string
{
    $path = sys_get_temp_dir().'/hone-client-test-'.bin2hex(random_bytes(6));

    mkdir($path, 0755, true);
    file_put_contents($path.'/.env', $env);
    file_put_contents($path.'/composer.json', $composer ?? json_encode(['require' => []], JSON_THROW_ON_ERROR));

    app()->setBasePath($path);
    app()->useEnvironmentPath($path);
    app()->loadEnvironmentFrom('.env');
    app()->useStoragePath($path.'/storage');

    return $path;
}

/**
 * @return list<string>
 */
function honeClientLines(string $contents, string $key): array
{
    return array_values(array_filter(
        preg_split('/\R/', $contents) ?: [],
        fn (string $line): bool => str_starts_with($line, $key.'=')
    ));
}

function bindFakeNightwatchCore(?Ingest $ingest = null): Core
{
    $core = (new ReflectionClass(Core::class))->newInstanceWithoutConstructor();
    $core->ingest = $ingest ?? Mockery::mock(Ingest::class);

    app()->instance(Core::class, $core);

    return $core;
}

/**
 * @return array<int, class-string|string>
 */
function honeClientGlobalMiddleware(): array
{
    $kernel = app(HttpKernelContract::class);

    assert($kernel instanceof Kernel);

    return $kernel->getGlobalMiddleware();
}

function honeIngest(
    int $bufferLimit = 500,
    float $flushInterval = 60,
    bool $runningInConsole = false,
    float $connectTimeout = 0.5,
    float $timeout = 0.5,
    ?LoggerInterface $logger = null,
    ?CacheRepository $cache = null,
    string $url = 'https://hone.test/ingest',
    bool $discoveryEnabled = true,
    ?IngestDiscovery $discovery = null,
): HoneIngest {
    $discovery ??= new IngestDiscovery(
        endpoint: new HoneEndpoint($url),
        cache: $cache,
        enabled: $discoveryEnabled,
    );

    return new HoneIngest(
        url: $url,
        token: 'secret-token',
        app: 'checkout',
        deploy: 'abc123',
        bufferLimit: $bufferLimit,
        flushInterval: $flushInterval,
        runningInConsole: $runningInConsole,
        connectTimeout: $connectTimeout,
        timeout: $timeout,
        http: app(Factory::class),
        logger: $logger ?? app(LoggerInterface::class),
        nightwatch: (new ReflectionClass(Core::class))->newInstanceWithoutConstructor(),
        cache: $cache,
        discovery: $discovery,
    );
}

function honeArrayCache(): Repository
{
    return new Repository(new ArrayStore);
}

/**
 * A logger that keeps every record so a test can assert on its level.
 */
function honeRecordingLogger(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /**
         * @var list<array{level: string, message: string, context: array<string, mixed>}>
         */
        public array $records = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
        }

        /**
         * @return list<array{level: string, message: string, context: array<string, mixed>}>
         */
        public function recordsAtLevel(string $level): array
        {
            return array_values(array_filter($this->records, fn (array $record): bool => $record['level'] === $level));
        }
    };
}

/**
 * Configure a Hone install the way a consuming application does, so the
 * container builds the ingest through the service provider.
 */
function configureHoneConsumer(): void
{
    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');
    config()->set('hone.app', 'checkout');
    config()->set('hone.discover_ingest', true);
    config()->set('cache.default', 'array');

    bindFakeNightwatchCore();
}

/**
 * Fake the ingest endpoint, recording each envelope and timing out on the
 * attempts named in $timeOutOnAttempts.
 *
 * @param  list<int>  $timeOutOnAttempts
 * @param  array<string, mixed>  $envelopes
 * @param  array<string, mixed>  $options
 */
function fakeHoneIngestEndpoint(array $timeOutOnAttempts, array &$envelopes, array &$options): void
{
    $attempt = 0;

    Http::fake(function (Request $request, array $sent) use (&$attempt, &$envelopes, &$options, $timeOutOnAttempts) {
        $attempt++;
        $envelopes[] = $request->data();
        $options = $sent;

        if (in_array($attempt, $timeOutOnAttempts, true)) {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 500 milliseconds with 0 bytes received'
            );
        }

        return Http::response([], 202);
    });
}

it('rebinds nightwatch core ingest when url and token are configured', function (): void {
    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    bindFakeNightwatchCore();

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBeInstanceOf(HoneIngest::class)
        ->and(honeClientGlobalMiddleware())->toHaveKey(0, CaptureResponseContext::class);
});

it('stays inert when neither url nor token are configured', function (): void {
    config()->set('hone.url', null);
    config()->set('hone.token', null);

    $original = Mockery::mock(Ingest::class);
    bindFakeNightwatchCore($original);

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBe($original)
        ->and(app(Core::class)->ingest)->not->toBeInstanceOf(HoneIngest::class)
        ->and(honeClientGlobalMiddleware())->not->toContain(CaptureResponseContext::class);
});

it('disables nightwatch when its token and Hone credentials are absent', function (): void {
    config()->set('hone.url', null);
    config()->set('hone.token', null);
    config()->set('nightwatch.token', null);
    config()->set('nightwatch.enabled', true);

    (new HoneClientServiceProvider(app()))->register();

    expect(config('nightwatch.enabled'))->toBeFalse();
});

it('preserves the nightwatch enabled setting when its token is configured', function (bool $enabled): void {
    config()->set('hone.url', null);
    config()->set('hone.token', null);
    config()->set('nightwatch.token', 'nightwatch-token');
    config()->set('nightwatch.enabled', $enabled);

    (new HoneClientServiceProvider(app()))->register();

    expect(config('nightwatch.enabled'))->toBe($enabled);
})->with([
    'true' => [true],
    'false' => [false],
]);

it('leaves nightwatch enabled when Hone is configured', function (): void {
    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');
    config()->set('nightwatch.token', null);
    config()->set('nightwatch.enabled', true);

    (new HoneClientServiceProvider(app()))->register();

    expect(config('nightwatch.enabled'))->toBeTrue();
});

it('does not resolve the contracts nudge when Hone is unconfigured', function (): void {
    config()->set('hone.url', null);
    config()->set('hone.token', null);
    app()->bind(ContractsVersionNudge::class, fn (): never => throw new RuntimeException('nudge resolved'));

    runHoneClientBootedRebind();

    expect(true)->toBeTrue();
});

it('stays inert and logs a warning when only url is configured', function (): void {
    Log::spy();

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', null);

    $original = Mockery::mock(Ingest::class);
    bindFakeNightwatchCore($original);

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBe($original)
        ->and(app(Core::class)->ingest)->not->toBeInstanceOf(HoneIngest::class)
        ->and(honeClientGlobalMiddleware())->not->toContain(CaptureResponseContext::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Hone is half-configured: set both HONE_URL and HONE_TOKEN, or neither.');
});

it('stays inert and logs a warning when only token is configured', function (): void {
    Log::spy();

    config()->set('hone.url', null);
    config()->set('hone.token', 'secret-token');

    $original = Mockery::mock(Ingest::class);
    bindFakeNightwatchCore($original);

    runHoneClientBootedRebind();

    expect(app(Core::class)->ingest)->toBe($original)
        ->and(app(Core::class)->ingest)->not->toBeInstanceOf(HoneIngest::class)
        ->and(honeClientGlobalMiddleware())->not->toContain(CaptureResponseContext::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Hone is half-configured: set both HONE_URL and HONE_TOKEN, or neither.');
});

it('stays inert when nightwatch core is not bound', function (): void {
    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    expect(app()->bound(Core::class))->toBeFalse();

    runHoneClientBootedRebind();

    expect(app()->bound(Core::class))->toBeFalse()
        ->and(honeClientGlobalMiddleware())->not->toContain(CaptureResponseContext::class);
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

it('sends the bfc client identity header alongside the token on digest', function (): void {
    config()->set('bfc-client.identity', 'install-abc123');

    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'sql' => 'select 1']);

    $ingest->digest();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://hone.test/ingest'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request->hasHeader('X-BfC-Client-Id', 'install-abc123');
    });
});

it('still delivers telemetry when the bfc client identity is unusable', function (): void {
    config()->set('bfc-client.identity', str_repeat('a', 256));

    Http::fake();

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'sql' => 'select 1']);

    $ingest->digest();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://hone.test/ingest'
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && ! $request->hasHeader('X-BfC-Client-Id');
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

it('keeps only the most recent records and reports overflow losses in web context', function (): void {
    Http::fake();

    $logger = new class extends AbstractLogger
    {
        public int $warnings = 0;

        /**
         * @param  array<string, mixed>  $context
         */
        public function log($level, string|Stringable $message, array $context = []): void
        {
            if ($level === 'warning') {
                $this->warnings++;
            }
        }
    };
    $ingest = honeIngest(bufferLimit: 3, logger: $logger);

    foreach (range(1, 5) as $index) {
        $ingest->write(['t' => 'query', 'index' => $index]);
    }

    Http::assertNothingSent();

    $ingest->digest();

    Http::assertSent(function (Request $request): bool {
        return count($request['records']) === 3
            && array_column($request['records'], 'index') === [3, 4, 5]
            && $request['losses'] === [
                'overflow_dropped_records' => 2,
                'failed_delivery_records' => 0,
            ];
    });

    expect($logger->warnings)->toBe(1);
});

it('sends every console record in batches as the buffer fills', function (): void {
    $batches = [];

    Http::fake(function (Request $request) use (&$batches) {
        $batches[] = array_column($request['records'], 'index');

        return Http::response([], 202);
    });

    $ingest = honeIngest(bufferLimit: 3, runningInConsole: true);

    foreach (range(1, 7) as $index) {
        $ingest->write(['t' => 'query', 'index' => $index]);
    }

    $ingest->digest();

    expect($batches)->toBe([[1, 2, 3], [4, 5, 6], [7]])
        ->and(array_merge(...$batches))->toBe(range(1, 7));
});

it('sends an aged console buffer on the next write', function (): void {
    Carbon::setTestNow('2026-10-07 12:00:00');
    Http::fake();

    try {
        $ingest = honeIngest(bufferLimit: 500, flushInterval: 60, runningInConsole: true);
        $ingest->write(['t' => 'query', 'index' => 1]);

        Http::assertNothingSent();

        Carbon::setTestNow('2026-10-07 12:01:01');
        $ingest->write(['t' => 'query', 'index' => 2]);

        Http::assertSent(function (Request $request): bool {
            return array_column($request['records'], 'index') === [1, 2];
        });
    } finally {
        Carbon::setTestNow();
    }
});

it('reports failed post records on the next successful envelope without resending them', function (): void {
    Http::fakeSequence()
        ->push([], 500)
        ->push([], 202)
        ->push([], 202);

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'index' => 1]);
    $ingest->write(['t' => 'query', 'index' => 2]);
    $ingest->digest();
    $ingest->write(['t' => 'query', 'index' => 3]);
    $ingest->digest();
    $ingest->write(['t' => 'query', 'index' => 4]);
    $ingest->digest();

    $requests = Http::recorded();

    expect($requests)->toHaveCount(3)
        ->and(array_column($requests[1][0]['records'], 'index'))->toBe([3])
        ->and($requests[1][0]['losses'])->toBe([
            'overflow_dropped_records' => 0,
            'failed_delivery_records' => 2,
        ])->and($requests[2][0]->data())->not->toHaveKey('losses');
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

it('lets the Nightwatch queue lifecycle digest each job without a later flush resending it', function (): void {
    Http::fake();

    $ingest = honeIngest(runningInConsole: true);
    $ingest->write(['t' => 'job-attempt', 'name' => 'SendReceipt']);

    $core = (new ReflectionClass(Core::class))->newInstanceWithoutConstructor();
    $core->ingest = $ingest;

    (new WorkerLifecycleListener($core))(new Looping('redis', 'default'));
    $ingest->flush();
    $ingest->digest();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return $request['records'] === [['t' => 'job-attempt', 'name' => 'SendReceipt']];
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

it('selects console and web timeout budgets from their context-specific config', function (bool $runningInConsole, float $expectedConnectTimeout, float $expectedTimeout): void {
    $sentOptions = null;

    Http::fake(function (Request $request, array $options) use (&$sentOptions) {
        $sentOptions = $options;

        return Http::response();
    });

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.connect_timeout' => 0.5,
        'hone.timeout' => 0.5,
        'hone.console_connect_timeout' => 2.0,
        'hone.console_timeout' => 5.0,
    ]);

    (new ReflectionProperty(app(), 'isRunningInConsole'))->setValue(app(), $runningInConsole);
    bindFakeNightwatchCore();
    (new HoneClientServiceProvider(app()))->register();

    $ingest = app(HoneIngest::class);
    $ingest->write(['t' => 'query']);
    $ingest->digest();

    expect($sentOptions['connect_timeout'])->toBe($expectedConnectTimeout)
        ->and($sentOptions['timeout'])->toBe($expectedTimeout);
})->with([
    'console' => [true, 2.0, 5.0],
    'web' => [false, 0.5, 0.5],
]);

it('implements the nightwatch ingest contract', function (): void {
    expect(honeIngest())->toBeInstanceOf(Ingest::class);
});

it('installs hone env values without duplicating them', function (): void {
    $path = useHoneClientTempApp();

    $this->artisan('hone:install', [
        '--url' => 'https://hone.test/ingest',
        '--token' => 'secret-token',
        '--no-interaction' => true,
    ])->doesntExpectOutputToContain('secret-token')
        ->assertExitCode(0);

    $this->artisan('hone:install', [
        '--url' => 'https://hone.test/ingest',
        '--token' => 'secret-token',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    $contents = (string) file_get_contents($path.'/.env');

    expect(honeClientLines($contents, 'HONE_URL'))->toHaveCount(1)
        ->and(honeClientLines($contents, 'HONE_TOKEN'))->toHaveCount(1)
        ->and($contents)->toContain('HONE_URL=https://hone.test/ingest')
        ->and($contents)->toContain('HONE_TOKEN=secret-token');
});

it('adds nightwatch enabled when installing', function (): void {
    $path = useHoneClientTempApp();

    $this->artisan('hone:install', [
        '--url' => 'https://hone.test/ingest',
        '--token' => 'secret-token',
        '--no-interaction' => true,
    ])->assertExitCode(0);

    expect((string) file_get_contents($path.'/.env'))->toContain('NIGHTWATCH_ENABLED=true');
});

it('checks the public Hone capabilities url without sending the bearer token', function (): void {
    Http::fake([
        'https://hone.test/capabilities' => Http::response([
            'envelope' => ['min_major' => 1, 'max_major' => 1, 'supported_majors' => [1]],
        ]),
    ]);

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    $this->artisan('hone:update')
        ->expectsOutput("Your Hone server understands envelope v1. You're good.")
        ->assertExitCode(0);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://hone.test/capabilities'
            && ! $request->hasHeader('Authorization');
    });
});

it('fails hone update when apps are ahead of the hone server', function (): void {
    Http::fake([
        'https://hone.test/capabilities' => Http::response([
            'envelope' => ['min_major' => 0, 'max_major' => 0, 'supported_majors' => [0]],
        ]),
    ]);

    config()->set('hone.url', 'https://hone.test');
    config()->set('hone.token', 'secret-token');

    $this->artisan('hone:update')
        ->expectsOutputToContain('Update your Hone app first')
        ->assertExitCode(1);
});

it('fails hone update without a configured url and sends nothing', function (): void {
    Http::fake();

    config()->set('hone.url', null);

    $this->artisan('hone:update')
        ->expectsOutputToContain('HONE_URL is not configured')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails hone update when the capabilities response is not successful', function (): void {
    Http::fake([
        'https://hone.test/capabilities' => Http::response(['error' => 'server down'], 503),
    ]);

    config()->set('hone.url', 'https://hone.test');
    config()->set('hone.token', 'secret-token');

    $this->artisan('hone:update')
        ->expectsOutputToContain('Could not fetch Hone server capabilities')
        ->assertExitCode(1);
});

it('fails hone update when capabilities json is invalid', function (): void {
    Http::fake([
        'https://hone.test/capabilities' => Http::response(['missing' => 'envelope']),
    ]);

    config()->set('hone.url', 'https://hone.test');
    config()->set('hone.token', 'secret-token');

    $this->artisan('hone:update')
        ->expectsOutputToContain('invalid capabilities JSON')
        ->assertExitCode(1);
});

it('schedules ingest discovery hourly when Hone is configured', function (): void {
    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    (new HoneClientServiceProvider(app()))->boot();

    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'hone:refresh-ingest'));

    expect($event->expression)->toBe('0 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});

it('does not schedule ingest discovery when it is disabled', function (): void {
    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => false,
    ]);

    (new HoneClientServiceProvider(app()))->boot();

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'hone:refresh-ingest'));

    expect($events)->toBeEmpty();
});

it('refreshes and uses a discovered ingest url with the console timeout budget', function (): void {
    $cache = honeArrayCache();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.app' => 'checkout',
        'hone.discover_ingest' => true,
        'hone.console_connect_timeout' => 1.25,
        'hone.console_timeout' => 4.5,
    ]);
    bindFakeNightwatchCore();

    $capabilityOptions = null;

    Http::preventStrayRequests();
    Http::fake(function (Request $request, array $options) use (&$capabilityOptions) {
        if ($request->url() === 'https://hone.test/capabilities') {
            $capabilityOptions = $options;

            return Http::response(['ingest' => ['url' => 'https://ingest.hone.test/ingest']]);
        }

        return Http::response([], 202);
    });

    $this->artisan('hone:refresh-ingest')
        ->expectsOutput('Hone ingest URL discovery refreshed.')
        ->doesntExpectOutputToContain('secret-token')
        ->assertExitCode(0);

    app(HoneIngest::class)->writeNow(['t' => 'query']);

    expect($cache->get((new HoneEndpoint('https://hone.test/ingest'))->discoveryCacheKey()))
        ->toBe('https://ingest.hone.test/ingest')
        ->and($capabilityOptions['connect_timeout'])->toBe(1.25)
        ->and($capabilityOptions['timeout'])->toBe(4.5);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/capabilities'
        && ! $request->hasHeader('Authorization'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ingest.hone.test/ingest'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer secret-token'));
});

it('places capabilities before the configured query without sending the bearer token', function (string $configuredUrl, string $capabilitiesUrl): void {
    app()->instance(CacheRepository::class, honeArrayCache());

    config()->set([
        'hone.url' => $configuredUrl,
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        $capabilitiesUrl => Http::response(['ingest' => ['url' => 'https://ingest.hone.test/ingest']]),
    ]);

    $this->artisan('hone:refresh-ingest')->assertExitCode(0);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $capabilitiesUrl
        && ! $request->hasHeader('Authorization'));
})->with([
    'canonical ingest endpoint' => [
        'https://hone.test/ingest?region=eu',
        'https://hone.test/capabilities?region=eu',
    ],
    'base origin' => [
        'https://hone.test?region=eu',
        'https://hone.test/capabilities?region=eu',
    ],
    'custom endpoint' => [
        'https://hone.test/custom-collector?region=eu',
        'https://hone.test/custom-collector/capabilities?region=eu',
    ],
]);

it('keeps query and fragment components after the capabilities path', function (): void {
    expect((new HoneEndpoint('https://hone.test/ingest?region=eu#client'))->capabilitiesUrl())
        ->toBe('https://hone.test/capabilities?region=eu#client');
});

it('keeps the configured endpoint as fallback and normalizes a base url once', function (string $configuredUrl, string $expectedUrl): void {
    Http::preventStrayRequests();
    Http::fake([
        $expectedUrl => Http::response([], 202),
    ]);

    honeIngest(url: $configuredUrl, discoveryEnabled: false)->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === $expectedUrl);
})->with([
    'full endpoint remains exact' => ['https://hone.test/ingest', 'https://hone.test/ingest'],
    'base without slash gains one ingest segment' => ['https://hone.test', 'https://hone.test/ingest'],
    'base with slash gains one ingest segment' => ['https://hone.test/', 'https://hone.test/ingest'],
    'canonical endpoint trailing slash remains exact' => ['https://hone.test/ingest/', 'https://hone.test/ingest/'],
    'custom full endpoint remains exact' => ['https://hone.test/custom-collector', 'https://hone.test/custom-collector'],
    'custom full endpoint trailing slash remains exact' => ['https://hone.test/custom-collector/', 'https://hone.test/custom-collector/'],
]);

it('keeps the original HoneIngest constructor usable without a discovery collaborator', function (): void {
    Http::fake([
        'https://hone.test/custom-collector/' => Http::response([], 202),
    ]);

    $ingest = new HoneIngest(
        url: 'https://hone.test/custom-collector/',
        token: 'secret-token',
        app: 'checkout',
        deploy: 'abc123',
        bufferLimit: 500,
        flushInterval: 60,
        runningInConsole: false,
        connectTimeout: 0.5,
        timeout: 0.5,
        http: app(Factory::class),
        logger: app(LoggerInterface::class),
        nightwatch: (new ReflectionClass(Core::class))->newInstanceWithoutConstructor(),
    );

    $ingest->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/custom-collector/');
});

it('falls back without discovery network traffic on every delivery path', function (string $path, string $cacheState): void {
    Carbon::setTestNow('2026-10-17 12:00:00');

    try {
        $cache = honeArrayCache();
        $endpoint = new HoneEndpoint('https://hone.test/ingest');
        $enabled = $cacheState !== 'disabled';

        if (in_array($cacheState, ['hit', 'expired'], true)) {
            $cache->put($endpoint->discoveryCacheKey(), 'https://discovered.test/ingest', IngestDiscovery::CACHE_TTL_SECONDS);
        }

        if ($cacheState === 'expired') {
            Carbon::setTestNow('2026-10-17 13:00:01');
        }

        $expectedUrl = $cacheState === 'hit'
            ? 'https://discovered.test/ingest'
            : 'https://hone.test/ingest';

        Http::preventStrayRequests();
        Http::fake([
            $expectedUrl => Http::response([], 202),
        ]);

        $ingest = honeIngest(
            discovery: new IngestDiscovery($endpoint, $cache, $enabled),
        );

        if ($path === 'digest') {
            $ingest->write(['t' => 'request']);
            $ingest->digest();
        } else {
            $ingest->writeNow(['t' => 'query']);
        }

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === $expectedUrl && $request->method() === 'POST');
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/capabilities'));
    } finally {
        Carbon::setTestNow();
    }
})->with([
    'terminate-equivalent digest' => ['digest', 'miss'],
    'write now' => ['writeNow', 'miss'],
    'cache hit' => ['writeNow', 'hit'],
    'cache miss' => ['writeNow', 'miss'],
    'expired cache' => ['writeNow', 'expired'],
    'discovery kill switch' => ['writeNow', 'disabled'],
]);

it('falls back when reading the discovery cache throws', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('get')->once()->andThrow(new RuntimeException('cache unavailable'));

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    honeIngest(
        discovery: new IngestDiscovery(new HoneEndpoint('https://hone.test/ingest'), $cache, true),
    )->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
});

it('falls back when a cached discovery value is invalid', function (): void {
    $cache = honeArrayCache();
    $endpoint = new HoneEndpoint('https://hone.test/ingest');
    $cache->put($endpoint->discoveryCacheKey(), 'https://user:password@attacker.test/ingest', 3600);

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    honeIngest(discovery: new IngestDiscovery($endpoint, $cache, true))->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
});

it('rejects invalid advertised ingest urls and leaves delivery on fallback', function (mixed $advertisedUrl): void {
    $cache = honeArrayCache();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/capabilities' => Http::response(['ingest' => ['url' => $advertisedUrl]]),
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    $this->artisan('hone:refresh-ingest')
        ->expectsOutput('Hone server returned an invalid ingest URL.')
        ->assertExitCode(1);

    honeIngest(
        discovery: new IngestDiscovery(new HoneEndpoint('https://hone.test/ingest'), $cache, true),
    )->writeNow(['t' => 'query']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
})->with([
    'missing' => [null],
    'relative' => ['/ingest'],
    'wrong scheme' => ['ftp://hone.test/ingest'],
    'credentials' => ['https://user:password@hone.test/ingest'],
    'fragment' => ['https://hone.test/ingest#fragment'],
    'https downgrade' => ['http://hone.test/ingest'],
]);

it('allows an advertised http ingest url when the configured Hone url uses http', function (): void {
    $cache = honeArrayCache();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'http://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://hone.test/capabilities' => Http::response(['ingest' => ['url' => 'http://local-ingest.test/ingest']]),
        'http://local-ingest.test/ingest' => Http::response([], 202),
    ]);

    $this->artisan('hone:refresh-ingest')->assertExitCode(0);
    honeIngest(
        url: 'http://hone.test/ingest',
        discovery: new IngestDiscovery(new HoneEndpoint('http://hone.test/ingest'), $cache, true),
    )->writeNow(['t' => 'query']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://hone.test/capabilities'
        && ! $request->hasHeader('Authorization'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://local-ingest.test/ingest');
});

it('lets a long-lived ingest observe discovery refresh and expiry', function (): void {
    Carbon::setTestNow('2026-10-17 12:00:00');

    try {
        $cache = honeArrayCache();
        $endpoint = new HoneEndpoint('https://hone.test/ingest');
        $discovery = new IngestDiscovery($endpoint, $cache, true);
        $ingest = honeIngest(discovery: $discovery);

        Http::preventStrayRequests();
        Http::fake([
            'https://hone.test/ingest' => Http::response([], 202),
            'https://discovered.test/ingest' => Http::response([], 202),
        ]);

        $ingest->writeNow(['t' => 'query', 'index' => 1]);

        expect($discovery->remember('https://discovered.test/ingest'))->toBeTrue();

        $ingest->writeNow(['t' => 'query', 'index' => 2]);

        Carbon::setTestNow('2026-10-17 13:00:01');
        $ingest->writeNow(['t' => 'query', 'index' => 3]);

        expect(Http::recorded()
            ->map(fn (array $recorded): string => $recorded[0]->url())
            ->all())->toBe([
                'https://hone.test/ingest',
                'https://discovered.test/ingest',
                'https://hone.test/ingest',
            ]);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/capabilities'));
    } finally {
        Carbon::setTestNow();
    }
});

it('falls back when capabilities contain invalid json', function (): void {
    $cache = honeArrayCache();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/capabilities' => Http::response('{invalid json', 200, ['Content-Type' => 'application/json']),
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    $this->artisan('hone:refresh-ingest')->assertExitCode(1);
    honeIngest(discovery: new IngestDiscovery(new HoneEndpoint('https://hone.test/ingest'), $cache, true))
        ->writeNow(['t' => 'query']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
});

it('falls back when the capability request fails', function (string $failure, int $recordedRequests): void {
    $cache = honeArrayCache();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($failure) {
        if ($request->url() === 'https://hone.test/capabilities') {
            if ($failure === 'connection') {
                throw new ConnectionException('network unavailable');
            }

            return Http::response([], 503);
        }

        return Http::response([], 202);
    });

    $this->artisan('hone:refresh-ingest')->assertExitCode(1);
    honeIngest(discovery: new IngestDiscovery(new HoneEndpoint('https://hone.test/ingest'), $cache, true))
        ->writeNow(['t' => 'query']);

    Http::assertSentCount($recordedRequests);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
})->with([
    'connection error' => ['connection', 1],
    'non-success response' => ['response', 2],
]);

it('leaves a fresh last-known-good discovery untouched when refresh fails', function (): void {
    $cache = honeArrayCache();
    $endpoint = new HoneEndpoint('https://hone.test/ingest');
    $discovery = new IngestDiscovery($endpoint, $cache, true);
    $discovery->remember('https://known-good.test/ingest');

    app()->instance(CacheRepository::class, $cache);
    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::fake([
        'https://hone.test/capabilities' => Http::response([], 503),
    ]);

    $this->artisan('hone:refresh-ingest')->assertExitCode(1);

    expect($discovery->destination())->toBe('https://known-good.test/ingest');
});

it('fails open when the discovery cache cannot store a refresh', function (): void {
    $cache = Mockery::mock(CacheRepository::class);
    $cache->shouldReceive('put')->once()->andReturnFalse();
    $cache->shouldReceive('get')->once()->andReturnNull();
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/capabilities' => Http::response(['ingest' => ['url' => 'https://discovered.test/ingest']]),
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    $this->artisan('hone:refresh-ingest')
        ->expectsOutputToContain('using the configured fallback')
        ->assertExitCode(1);

    honeIngest(
        discovery: new IngestDiscovery(new HoneEndpoint('https://hone.test/ingest'), $cache, true),
    )->writeNow(['t' => 'query']);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
});

it('does not refresh or use discovery when the kill switch is off', function (): void {
    $cache = honeArrayCache();
    $endpoint = new HoneEndpoint('https://hone.test/ingest');
    $cache->put($endpoint->discoveryCacheKey(), 'https://discovered.test/ingest', 3600);
    app()->instance(CacheRepository::class, $cache);

    config()->set([
        'hone.url' => 'https://hone.test/ingest',
        'hone.token' => 'secret-token',
        'hone.discover_ingest' => false,
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'https://hone.test/ingest' => Http::response([], 202),
    ]);

    $this->artisan('hone:refresh-ingest')
        ->expectsOutputToContain('discovery is disabled')
        ->assertExitCode(0);

    honeIngest(discovery: new IngestDiscovery($endpoint, $cache, false))->writeNow(['t' => 'query']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hone.test/ingest');
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/capabilities'));
});

it('scopes discovered ingest urls by configured Hone url', function (): void {
    $cache = honeArrayCache();
    $first = new IngestDiscovery(new HoneEndpoint('https://first.test/ingest'), $cache, true);
    $second = new IngestDiscovery(new HoneEndpoint('https://second.test/ingest'), $cache, true);

    expect($first->remember('https://first-ingest.test/ingest'))->toBeTrue()
        ->and($second->destination())->toBe('https://second.test/ingest');

    expect($second->remember('https://second-ingest.test/ingest'))->toBeTrue()
        ->and($first->destination())->toBe('https://first-ingest.test/ingest')
        ->and($second->destination())->toBe('https://second-ingest.test/ingest');
});

it('fails discovery without complete configuration and sends nothing', function (?string $url, ?string $token): void {
    config()->set([
        'hone.url' => $url,
        'hone.token' => $token,
        'hone.discover_ingest' => true,
    ]);

    Http::preventStrayRequests();
    Http::fake();

    $this->artisan('hone:refresh-ingest')
        ->expectsOutputToContain('requires HONE_URL and HONE_TOKEN')
        ->assertExitCode(1);

    Http::assertNothingSent();
})->with([
    'missing url' => [null, 'secret-token'],
    'missing token' => ['https://hone.test/ingest', null],
]);

it('nudges once when the stored contracts major changes', function (): void {
    $path = useHoneClientTempApp();
    $marker = $path.'/storage/framework/hone/contracts-major';

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    File::ensureDirectoryExists(dirname($marker));
    File::put($marker, '0');
    Log::spy();

    runHoneClientBootedRebind();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Hone contracts major changed; run `php artisan hone:update` to verify server compatibility.');

    expect((string) file_get_contents($marker))->toBe((string) Envelope::VERSION);
});

it('does not nudge when the stored contracts major matches', function (): void {
    $path = useHoneClientTempApp();
    $marker = $path.'/storage/framework/hone/contracts-major';

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    File::ensureDirectoryExists(dirname($marker));
    File::put($marker, (string) Envelope::VERSION);
    Log::spy();

    runHoneClientBootedRebind();

    Log::shouldNotHaveReceived('warning');
});

it('persists contracts major without nudging on first run', function (): void {
    $path = useHoneClientTempApp();
    $marker = $path.'/storage/framework/hone/contracts-major';

    config()->set('hone.url', 'https://hone.test/ingest');
    config()->set('hone.token', 'secret-token');

    Log::spy();

    runHoneClientBootedRebind();

    Log::shouldNotHaveReceived('warning');

    expect($marker)->toBeFile()
        ->and((string) file_get_contents($marker))->toBe((string) Envelope::VERSION);
});

it('never drops a web batch silently when the ingest post times out', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([1], $envelopes, $options);

    $logger = honeRecordingLogger();
    $ingest = honeIngest(logger: $logger);

    $ingest->write(['t' => 'query', 'index' => 1]);
    $ingest->write(['t' => 'query', 'index' => 2]);
    $ingest->digest();

    $warnings = $logger->recordsAtLevel('warning');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toBe('Hone ingest delivery failed; dropped 2 telemetry records.')
        ->and($warnings[0]['context']['dropped_records'])->toBe(2)
        ->and($warnings[0]['context']['exception'])->toBeInstanceOf(ConnectionException::class)
        ->and($logger->recordsAtLevel('debug'))->toBe([]);
});

it('gives a web ingest post the observed Hone latency as its budget', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([], $envelopes, $options);

    // The suite always runs in console, so the web budget is taken from the
    // shipped config the way the service provider takes it for a web request.
    $ingest = honeIngest(
        connectTimeout: (float) config('hone.connect_timeout'),
        timeout: (float) config('hone.timeout'),
    );

    $ingest->write(['t' => 'query', 'index' => 1]);
    $ingest->digest();

    expect($envelopes)->toHaveCount(1)
        ->and($options['timeout'])->toBe(2.5)
        ->and($options['connect_timeout'])->toBe(0.5);
});

it('carries a dropped batch loss count to Hone in a later request', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([1], $envelopes, $options);

    configureHoneConsumer();

    $terminated = app(HoneIngest::class);
    $terminated->write(['t' => 'query', 'index' => 1]);
    $terminated->write(['t' => 'query', 'index' => 2]);
    $terminated->digest();

    unset($terminated);

    $later = app(HoneIngest::class);
    $later->write(['t' => 'query', 'index' => 3]);
    $later->digest();

    expect($envelopes)->toHaveCount(2)
        ->and(array_column($envelopes[1]['records'], 'index'))->toBe([3])
        ->and($envelopes[1])->toHaveKey('losses.overflow_dropped_records', 0)
        ->and($envelopes[1])->toHaveKey('losses.failed_delivery_records', 2);
});

it('reports a carried loss count once', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([1], $envelopes, $options);

    configureHoneConsumer();

    $terminated = app(HoneIngest::class);
    $terminated->write(['t' => 'query', 'index' => 1]);
    $terminated->digest();

    $later = app(HoneIngest::class);
    $later->write(['t' => 'query', 'index' => 2]);
    $later->digest();

    $afterwards = app(HoneIngest::class);
    $afterwards->write(['t' => 'query', 'index' => 3]);
    $afterwards->digest();

    expect($envelopes)->toHaveCount(3)
        ->and($envelopes[1])->toHaveKey('losses.failed_delivery_records', 1)
        ->and($envelopes[2])->not->toHaveKey('losses');
});

it('keeps a loss count in memory when the application has no usable cache', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([1], $envelopes, $options);

    $ingest = honeIngest();
    $ingest->write(['t' => 'query', 'index' => 1]);
    $ingest->digest();
    $ingest->write(['t' => 'query', 'index' => 2]);
    $ingest->digest();

    expect($envelopes)->toHaveCount(2)
        ->and($envelopes[1]['losses'])->toBe([
            'overflow_dropped_records' => 0,
            'failed_delivery_records' => 1,
        ]);
});

it('sends the deploy Nightwatch resolved when the install sets none of its own', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([], $envelopes, $options);

    configureHoneConsumer();
    config()->set('hone.deploy', null);
    config()->set('nightwatch.deployment', 'cloud-deploy-uuid');

    $ingest = app(HoneIngest::class);
    $ingest->write(['t' => 'query']);
    $ingest->digest();

    expect($envelopes[0]['deploy'])->toBe('cloud-deploy-uuid');
});

it('prefers an explicit hone deploy over the one Nightwatch resolved', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([], $envelopes, $options);

    configureHoneConsumer();
    config()->set('hone.deploy', 'explicit-deploy');
    config()->set('nightwatch.deployment', 'cloud-deploy-uuid');

    $ingest = app(HoneIngest::class);
    $ingest->write(['t' => 'query']);
    $ingest->digest();

    expect($envelopes[0]['deploy'])->toBe('explicit-deploy');
});

it('sends no deploy when neither hone nor nightwatch resolved one', function (): void {
    $envelopes = [];
    $options = [];
    fakeHoneIngestEndpoint([], $envelopes, $options);

    configureHoneConsumer();
    config()->set('hone.deploy', null);
    config()->set('nightwatch.deployment', null);

    $ingest = app(HoneIngest::class);
    $ingest->write(['t' => 'query']);
    $ingest->digest();

    expect($envelopes[0]['deploy'])->toBeNull();
});

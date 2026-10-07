<?php

declare(strict_types=1);

use ArtisanBuild\HoneClient\ContractsVersionNudge;
use ArtisanBuild\HoneClient\HoneClientServiceProvider;
use ArtisanBuild\HoneClient\HoneIngest;
use ArtisanBuild\HoneClient\Http\Middleware\CaptureResponseContext;
use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel;
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
): HoneIngest {
    return new HoneIngest(
        url: 'https://hone.test/ingest',
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
    );
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

    $original = new stdClass;
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

    $original = new stdClass;
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

    $original = new stdClass;
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

it('checks hone server capabilities at the derived url with bearer token', function (): void {
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
            && $request->hasHeader('Authorization', 'Bearer secret-token');
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

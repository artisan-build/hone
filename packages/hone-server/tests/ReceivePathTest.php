<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\SystemAuthorityBusFrame;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Contracts\AsnLookup;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Normalizer;
use ArtisanBuild\HoneServer\Support\IptoAsnLookup;
use ArtisanBuild\HoneServer\Tests\Support\ReceivePathCorpus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(WithCredentials::class);

it('accepts a valid envelope and dispatches the telemetry batch without writing synchronously', function (): void {
    Queue::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);

    $envelope = Envelope::make(
        app: 'forged-app',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select * from users'],
        ],
        overflowDroppedRecords: 2,
        failedDeliveryRecords: 3,
    );

    $this->actingAsCredential($credential)
        ->postJson('/ingest', $envelope->toArray())
        ->assertStatus(202);

    Queue::assertPushed(ProcessTelemetryBatch::class, function (ProcessTelemetryBatch $job): bool {
        return $job->app === 'checkout'
            && $job->deploy === 'abc123'
            && $job->sentAt === '2026-06-09T12:00:00+00:00'
            && $job->records === [['t' => 'query', 'sql' => 'select * from users']]
            && $job->overflowDroppedRecords === 2
            && $job->failedDeliveryRecords === 3;
    });
    expect(RawEvent::query()->count())->toBe(0);
});

it('rejects missing bearer tokens without dispatching', function (): void {
    Queue::fake();

    $this->postJson('/ingest', Envelope::make('checkout', null, '2026-06-09T12:00:00+00:00', [])->toArray())
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

it('rejects unknown bearer tokens without dispatching', function (): void {
    Queue::fake();

    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/ingest', Envelope::make('checkout', null, '2026-06-09T12:00:00+00:00', [])->toArray())
        ->assertStatus(401);

    Queue::assertNothingPushed();
});

it('rejects credentials outside the installation-owned ingest purpose without dispatching', function (array $attributes): void {
    Queue::fake();
    $credential = $this->mintCredential($attributes);

    $this->actingAsCredential($credential)
        ->postJson('/ingest', Envelope::make('checkout', null, '2026-06-09T12:00:00+00:00', [])->toArray())
        ->assertUnauthorized();

    Queue::assertNothingPushed();
    expect($credential->credential->refresh()->last_used_at)->toBeNull();
})->with([
    'MCP purpose' => [[
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]],
    'application subject' => [[
        'purpose' => CredentialPurpose::SystemDeployment,
        'subject_type' => SubjectType::Application,
        'subject_ref' => 'checkout',
    ]],
]);

it('rejects account-bound and revoked ingest credentials without dispatching', function (bool $revoked): void {
    Queue::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
        'user_id' => $revoked ? null : 'account-user',
        'revoked_at' => $revoked ? now() : null,
    ]);

    $this->actingAsCredential($credential)
        ->postJson('/ingest', Envelope::make('checkout', null, '2026-06-09T12:00:00+00:00', [])->toArray())
        ->assertUnauthorized();

    Queue::assertNothingPushed();
    expect($credential->credential->refresh()->last_used_at)->toBeNull();
})->with([
    'account-bound' => [false],
    'revoked' => [true],
]);

it('rejects newer envelope versions with an upgrade message without dispatching', function (): void {
    Queue::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);

    $this->actingAsCredential($credential)
        ->postJson('/ingest', [
            'envelope_version' => Envelope::VERSION + 1,
            'app' => 'checkout',
            'records' => [],
        ])
        ->assertStatus(422)
        ->assertSee('Upgrade your Hone app');

    Queue::assertNothingPushed();
});

it('rejects malformed envelope bodies without dispatching', function (): void {
    Queue::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);

    $this->actingAsCredential($credential)
        ->postJson('/ingest', ['nope' => true])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('rejects non-decodable json without dispatching', function (): void {
    Queue::fake();
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);

    $this->call('POST', '/ingest', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => $credential->bearerHeader(),
    ], '{not json')
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('reports supported envelope capabilities with the named ingest route when no dedicated URL is configured', function (?string $ingestUrl): void {
    config()->set('hone-server.ingest_url', $ingestUrl);

    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', Envelope::VERSION)
        ->assertJsonPath('envelope.supported_majors', range(1, Envelope::VERSION))
        ->assertJsonPath('ingest.url', route('hone-server.ingest'));
})->with([
    'unset' => [null],
    'empty' => [''],
]);

it('reports supported envelope capabilities with a configured dedicated ingest URL', function (): void {
    config()->set('hone-server.ingest_url', 'https://ingest.example.com/ingest');

    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', Envelope::VERSION)
        ->assertJsonPath('envelope.supported_majors', range(1, Envelope::VERSION))
        ->assertJsonPath('ingest.url', 'https://ingest.example.com/ingest');
});

it('processes telemetry batches into raw events', function (): void {
    $job = new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: ReceivePathCorpus::basicRecords(),
    );

    $job->handle(resolve(AsnLookup::class));

    $events = RawEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('record_type')->all())->toBe(['query', 'request'])
        ->and($events->pluck('app')->unique()->values()->all())->toBe(['checkout'])
        ->and($events->pluck('deploy')->unique()->values()->all())->toBe(['abc123'])
        ->and($events[0]->normalized_key)->toBe('select * from users where id = ?')
        ->and($events[1]->normalized_key)->toBe('GET /')
        ->and($events[0]->payload)->toEqual(['t' => 'query', 'sql' => 'select * from users where id = ?', 'duration' => 12_000, 'timestamp' => '2026-06-09T12:00:01+00:00'])
        ->and($events[1]->payload)->toEqual(['t' => 'request', 'method' => 'GET', 'route' => '/', 'duration_ms' => 34, 'ts' => 1781006402000])
        ->and($events->pluck('duration_ms')->all())->toBe([12.0, 34.0])
        ->and($events[0]->occurred_at)->not->toBeNull()
        ->and($events[1]->occurred_at)->not->toBeNull();
});

it('persists large telemetry batches in bounded bulk inserts', function (): void {
    $records = array_map(
        fn (int $index): array => ['t' => 'query', 'sql' => 'select '.$index, 'duration_ms' => $index],
        range(1, 1201),
    );
    $rawEventInsertBindings = [];

    DB::connection('hone')->listen(function (QueryExecuted $query) use (&$rawEventInsertBindings): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "raw_events"')) {
            $rawEventInsertBindings[] = count($query->bindings);
        }
    });

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: $records,
    ))->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->count())->toBe(1201)
        ->and($rawEventInsertBindings)->toHaveCount(3)
        ->and(max($rawEventInsertBindings))->toBeLessThan(65535)
        ->and(RawEvent::query()->where('normalized_key', 'select 1201')->value('duration_ms'))->toBe(1201.0);
});

it('persists a batch containing an over-length query key in a single chunk insert', function (): void {
    Log::spy();
    $longSql = 'select * from orders where reference in ('.implode(', ', array_fill(0, 40, '?')).') and tenant = '.str_repeat('x', 200);

    expect(mb_strlen($longSql))->toBeGreaterThan(255);

    $rawEventInserts = 0;

    DB::connection('hone')->listen(function (QueryExecuted $query) use (&$rawEventInserts): void {
        if (str_starts_with(strtolower($query->sql), 'insert into "raw_events"')) {
            $rawEventInserts++;
        }
    });

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select good_one'],
            ['t' => 'query', 'sql' => $longSql],
            ['t' => 'query', 'sql' => 'select good_two'],
        ],
    ))->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->count())->toBe(3)
        ->and($rawEventInserts)->toBe(1)
        ->and(RawEvent::query()->orderBy('id')->pluck('normalized_key')->all())
        ->toBe(['select good_one', Normalizer::keyFor('query', ['sql' => $longSql]), 'select good_two']);

    Log::shouldNotHaveReceived('warning');
});

it('falls back around a rejected row and logs the record type and cause without row values', function (): void {
    Log::spy();
    DB::connection('hone')->statement("ALTER TABLE raw_events ADD CONSTRAINT raw_events_reject_queries CHECK (record_type <> 'query')");

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'request', 'method' => 'GET', 'route' => '/one'],
            ['t' => 'query', 'sql' => 'select secret_value from users'],
            ['t' => 'request', 'method' => 'GET', 'route' => '/two'],
        ],
    ))->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->orderBy('id')->pluck('normalized_key')->all())
        ->toBe(['GET /one', 'GET /two']);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to persist Hone telemetry record (query); the telemetry database rejected the row; PDOException SQLSTATE[23514]')
            && ! str_contains($message, 'raw_events_reject_queries')
            && ! str_contains($message, 'secret_value')
            && $context['record_type'] === 'query');
});

it('does not log database-bound row values that Postgres puts in its primary error line', function (): void {
    Log::spy();

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select 1', 'timestamp' => 10000000000000000],
        ],
    ))->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->count())->toBe(0);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to persist Hone telemetry record (query)')
            && str_contains($message, 'SQLSTATE[22008]')
            && ! str_contains($message, '318857-05-20 17:46:40')
            && $context['record_type'] === 'query');
});

it('logs unrecognized client record types as custom', function (): void {
    Log::spy();

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => str_repeat('custom-type', 30)],
        ],
    ))->handle(resolve(AsnLookup::class));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to persist Hone telemetry record (custom)')
            && ! str_contains($message, 'custom-type')
            && $context['record_type'] === 'custom');
});

it('processes telemetry jobs serialized before loss counters were added', function (): void {
    $class = ProcessTelemetryBatch::class;
    $properties = [
        'connection' => null,
        'app' => 'checkout',
        'deploy' => 'legacy-deploy',
        'sentAt' => '2026-06-09T12:00:00+00:00',
        'records' => [['t' => 'query', 'sql' => 'select 1']],
    ];
    $serialized = 'O:'.strlen($class).':"'.$class.'":'.count($properties).':{';

    foreach ($properties as $property => $value) {
        $serialized .= serialize($property).serialize($value);
    }

    $serialized .= '}';

    $job = unserialize($serialized, ['allowed_classes' => [$class]]);

    expect($job)->toBeInstanceOf(ProcessTelemetryBatch::class);

    $job->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->sole()->payload)->toBe(['t' => 'query', 'sql' => 'select 1'])
        ->and(DB::connection('hone')->table('ingest_counters')->count())->toBe(0);
});

it('accumulates client loss counters per token app and deploy', function (): void {
    $asnLookup = resolve(AsnLookup::class);

    (new ProcessTelemetryBatch('checkout', 'abc123', now()->toAtomString(), [], 2, 3))->handle($asnLookup);
    (new ProcessTelemetryBatch('checkout', 'abc123', now()->toAtomString(), [], 5, 7))->handle($asnLookup);
    (new ProcessTelemetryBatch('checkout', null, now()->toAtomString(), [], 11, 13))->handle($asnLookup);

    $counters = DB::connection('hone')->table('ingest_counters')
        ->orderByRaw('deploy nulls last')
        ->get();

    expect($counters)->toHaveCount(2)
        ->and($counters[0]->app)->toBe('checkout')
        ->and($counters[0]->deploy)->toBe('abc123')
        ->and($counters[0]->overflow_dropped_records)->toBe(7)
        ->and($counters[0]->failed_delivery_records)->toBe(10)
        ->and($counters[1]->deploy)->toBeNull()
        ->and($counters[1]->overflow_dropped_records)->toBe(11)
        ->and($counters[1]->failed_delivery_records)->toBe(13);
});

it('enriches real Nightwatch request records without changing their opaque payloads', function (): void {
    $records = ReceivePathCorpus::requestRecords();
    $job = new ProcessTelemetryBatch('checkout', 'abc123', '2026-06-09T12:00:00+00:00', $records);

    $job->handle(new IptoAsnLookup(__DIR__.'/Fixtures/ip2asn.tsv'));

    $events = RawEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('actor')->all())->toBe(['guest', 'guest', 'human'])
        ->and($events->pluck('ran_queries')->all())->toBe([true, false, true])
        ->and($events[0]->client_ip)->toBe('1.1.1.1')
        ->and($events[0]->asn)->toBe(13335)
        ->and($events[0]->request_path)->toBe('/')
        ->and($events[0]->request_host)->toBe('checkout.example.com')
        ->and($events[0]->user_agent)->toBe('Mozilla/5.0')
        ->and($events[0]->response)->toBe([
            'status' => 200,
            'content_type' => 'text/html; charset=UTF-8',
            'cache_control' => 'no-cache, private',
        ])
        ->and($events[1]->client_ip)->toBe('1.1.1.1')
        ->and($events[1]->asn)->toBe(13335)
        ->and($events[1]->request_path)->toBe('/static')
        ->and($events[1]->user_agent)->toBe('curl/8.0')
        ->and($events[2]->user_agent)->toBeNull()
        ->and($events->pluck('payload')->all())->toEqual($records);
});

it('classifies background execution records and compatibility aliases', function (): void {
    $job = new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: ReceivePathCorpus::backgroundRecords(),
    );

    $job->handle(new IptoAsnLookup(null));

    $events = RawEvent::query()->orderBy('id')->get();

    expect($events->pluck('actor')->all())->toBe(['scheduled', null, 'job', 'command'])
        ->and($events->pluck('ran_queries')->all())->toBe([false, null, true, true]);
});

it('falls back to the remote IP when CF-Connecting-IP is unusable', function (string $cloudflareIp): void {
    $job = new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [[
            'v' => 1,
            't' => 'request',
            'method' => 'GET',
            'route_path' => '/',
            'user' => '',
            'queries' => 0,
            'ip' => '8.8.8.8',
            'headers' => json_encode(['CF-Connecting-IP' => [$cloudflareIp]], JSON_THROW_ON_ERROR),
        ]],
    );

    $job->handle(new IptoAsnLookup(__DIR__.'/Fixtures/ip2asn.tsv'));

    $event = RawEvent::query()->sole();

    expect($event->client_ip)->toBe('8.8.8.8')
        ->and($event->asn)->toBe(15169);
})->with([
    'malformed' => ['not-an-ip'],
    'private' => ['10.0.0.1'],
]);

it('resolves only public addresses from the local iptoasn fixture', function (?string $ipAddress, ?int $expectedAsn): void {
    $lookup = new IptoAsnLookup(__DIR__.'/Fixtures/ip2asn.tsv');

    expect($lookup->lookup($ipAddress))->toBe($expectedAsn);
})->with([
    'public address' => ['1.1.1.1', 13335],
    'unknown public IPv4' => ['9.9.9.9', null],
    'unknown public IPv6' => ['2606:4700:4700::1111', null],
    'RFC1918' => ['10.10.10.10', null],
    'loopback' => ['127.0.0.1', null],
    'link-local' => ['169.254.1.1', null],
    'CGNAT' => ['100.64.1.1', null],
    'IPv6 ULA' => ['fd00::1', null],
    'malformed' => ['not-an-ip', null],
    'absent' => [null, null],
]);

it('uses a sparse index and bounds its exact-IP cache', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hone-asn-');

    expect($path)->toBeString();

    /** @var string $path */
    $firstAddress = ip2long('11.0.0.0');
    $lines = [];

    for ($offset = 0; $offset < 4096; $offset++) {
        $address = long2ip($firstAddress + $offset);
        $lines[] = "{$address}\t{$address}\t".(64512 + $offset)."\tZZ\tFixture";
    }

    file_put_contents($path, implode("\n", $lines)."\n");

    try {
        $lookup = new IptoAsnLookup($path, cacheLimit: 2, indexStride: 16);

        expect($lookup->lookup('11.0.15.255'))->toBe(68607);

        $reflection = new ReflectionClass($lookup);
        $index = $reflection->getProperty('index')->getValue($lookup);
        $inspectedLines = $reflection->getProperty('lastLookupInspectedLines')->getValue($lookup);

        expect($index)->toBeArray()
            ->and($index[4])->toHaveCount(256)
            ->and($inspectedLines)->toBeLessThanOrEqual(16);

        $lookup->lookup('11.0.0.0');
        $lookup->lookup('11.0.0.1');

        $cache = $reflection->getProperty('cache')->getValue($lookup);

        expect($cache)->toHaveCount(2)
            ->and(array_key_exists('11.0.15.255', $cache))->toBeFalse();
    } finally {
        @unlink($path);
    }
});

it('stops an unknown IPv4 lookup when a terminal sparse block reaches the IPv6 tail', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hone-asn-');

    expect($path)->toBeString();

    /** @var string $path */
    $lines = [
        "8.8.8.0\t8.8.8.0\t15169\tUS\tFixture",
        "8.8.8.1\t8.8.8.1\t15169\tUS\tFixture",
        "8.8.8.2\t8.8.8.2\t15169\tUS\tFixture",
        "8.8.8.3\t8.8.8.3\t15169\tUS\tFixture",
    ];

    for ($offset = 0; $offset < 4096; $offset++) {
        $address = '2001:db8::'.dechex($offset);
        $lines[] = "{$address}\t{$address}\t64500\tZZ\tIPv6 fixture";
    }

    file_put_contents($path, implode("\n", $lines)."\n");

    try {
        $lookup = new IptoAsnLookup($path, indexStride: 16);

        expect($lookup->lookup('9.9.9.9'))->toBeNull();

        $reflection = new ReflectionClass($lookup);
        $inspectedLines = $reflection->getProperty('lastLookupInspectedLines')->getValue($lookup);

        expect($inspectedLines)->toBe(5);
    } finally {
        @unlink($path);
    }
});

it('refreshes its index and cache when the TSV is replaced', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hone-asn-');

    expect($path)->toBeString();

    /** @var string $path */
    file_put_contents($path, "1.1.1.0\t1.1.1.255\t13335\tUS\tFirst\n");
    $lookup = new IptoAsnLookup($path);

    try {
        expect($lookup->lookup('1.1.1.1'))->toBe(13335);

        $replacement = $path.'.new';
        file_put_contents($replacement, "1.1.1.0\t1.1.1.255\t64500\tUS\tReplacement\n");
        rename($replacement, $path);

        expect($lookup->lookup('1.1.1.1'))->toBe(64500);

        $previousMtime = filemtime($path);
        file_put_contents($path, "1.1.1.0\t1.1.1.255\t64501\tUS\tChanged in place\n");
        touch($path, (is_int($previousMtime) ? $previousMtime : time()) + 2);

        expect($lookup->lookup('1.1.1.1'))->toBe(64501);
    } finally {
        @unlink($path);
        @unlink($path.'.new');
    }
});

it('returns null when the TSV is missing or unreadable', function (): void {
    $missingPath = sys_get_temp_dir().'/hone-asn-missing-'.bin2hex(random_bytes(8));

    expect((new IptoAsnLookup($missingPath))->lookup('1.1.1.1'))->toBeNull();

    $unreadablePath = tempnam(sys_get_temp_dir(), 'hone-asn-');

    expect($unreadablePath)->toBeString();

    /** @var string $unreadablePath */
    file_put_contents($unreadablePath, "1.1.1.0\t1.1.1.255\t13335\tUS\tFixture\n");
    chmod($unreadablePath, 0000);

    try {
        expect((new IptoAsnLookup($unreadablePath))->lookup('1.1.1.1'))->toBeNull();
    } finally {
        chmod($unreadablePath, 0600);
        @unlink($unreadablePath);
    }
});

it('frames telemetry queue processing as package system authority and cleans up afterward', function (): void {
    $job = new ProcessTelemetryBatch('checkout', null, now()->toAtomString(), []);
    $context = resolve(SystemAuthorityContext::class);
    $activeDuringInvocation = false;

    expect($context->active())->toBeFalse();

    $result = resolve(SystemAuthorityBusFrame::class)->handle(
        $job,
        function (ProcessTelemetryBatch $invoked) use ($job, $context, &$activeDuringInvocation): string {
            expect($invoked)->toBe($job);
            $activeDuringInvocation = $context->active();

            return 'processed';
        },
    );

    expect($result)->toBe('processed')
        ->and($activeDuringInvocation)->toBeTrue()
        ->and($context->active())->toBeFalse();
});

it('normalizes raw Nightwatch record type values', function (string $recordType, array $payload, string $expectedKey): void {
    expect(Normalizer::keyFor($recordType, $payload))->toBe($expectedKey);
})->with([
    'queued job' => ['queued-job', ['name' => 'SendWelcomeEmail'], 'SendWelcomeEmail'],
    'job attempt' => ['job-attempt', ['name' => 'SendWelcomeEmail'], 'SendWelcomeEmail'],
    'outgoing request' => ['outgoing-request', ['method' => 'GET', 'host' => 'api.example.com'], 'GET api.example.com'],
    'scheduled task' => ['scheduled-task', ['name' => 'backup:run'], 'backup:run'],
    'log level' => ['log', ['level' => 'error'], 'error'],
    'user id' => ['user', ['id' => '42'], '42'],
    'cache store and type' => ['cache-event', ['store' => 'redis', 'type' => 'hit'], 'redis:hit'],
    'request by route path' => ['request', ['method' => 'GET', 'route_path' => '/posts/{post}'], 'GET /posts/{post}'],
    'request without a matched route keeps the method' => ['request', ['method' => 'POST', 'route_path' => ''], 'POST'],
]);

it('leaves keys at or under 255 characters byte-identical', function (): void {
    $exactLimit = 'select '.str_repeat('a', 248);

    expect(mb_strlen($exactLimit))->toBe(255)
        ->and(Normalizer::keyFor('query', ['sql' => $exactLimit]))->toBe($exactLimit)
        ->and(Normalizer::keyFor('query', ['sql' => 'select 1']))->toBe('select 1');
});

it('bounds over-length keys deterministically without colliding on a shared prefix', function (): void {
    $sharedPrefix = 'select * from orders where '.str_repeat('a', 240);
    $first = Normalizer::keyFor('query', ['sql' => $sharedPrefix.' and status = 1']);
    $second = Normalizer::keyFor('query', ['sql' => $sharedPrefix.' and status = 2']);

    expect(mb_strlen($first))->toBe(255)
        ->and(mb_strlen($second))->toBe(255)
        ->and($first)->not->toBe($second)
        ->and($first)->toStartWith('select * from orders where ')
        ->and(Normalizer::keyFor('query', ['sql' => $sharedPrefix.' and status = 1']))->toBe($first);
});

it('re-keys a short key shaped like the hashed form so it cannot collide with a long key', function (): void {
    $longSql = 'select 1 -- '.str_repeat('x', 300);
    $storedKey = Normalizer::keyFor('query', ['sql' => $longSql]);

    // A client whose literal 255-char SQL equals the stored hashed form of some
    // long key lands in the reserved namespace and is re-keyed, so the two
    // inputs keep distinct identities.
    $rekeyed = Normalizer::keyFor('query', ['sql' => $storedKey]);

    expect(mb_strlen($storedKey))->toBe(255)
        ->and($rekeyed)->not->toBe($storedKey)
        ->and(mb_strlen($rekeyed))->toBe(255)
        ->and(Normalizer::keyFor('query', ['sql' => $storedKey]))->toBe($rekeyed);
});

it('keeps a long SQL key distinct from an identical-looking short SQL key through to aggregates', function (): void {
    $longSql = 'select 1 -- '.str_repeat('x', 300);
    $shortSql = Normalizer::keyFor('query', ['sql' => $longSql]);

    (new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: null,
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => $longSql],
            ['t' => 'query', 'sql' => $shortSql],
        ],
    ))->handle(resolve(AsnLookup::class));

    expect(RawEvent::query()->count())->toBe(2)
        ->and(RawEvent::query()->distinct('normalized_key')->count())->toBe(2);
});

it('bounds every record type key to 255 characters', function (string $recordType, array $record): void {
    expect(mb_strlen(Normalizer::keyFor($recordType, $record)))->toBeLessThanOrEqual(255);
})->with(ReceivePathCorpus::boundedKeys());

it('persists after response on sync queues using the token app instead of envelope app', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('hone-server.queue', 'sync');
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);

    $envelope = Envelope::make(
        app: 'forged-app',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select 1'],
            ['t' => 'request', 'method' => 'GET', 'route' => '/'],
        ],
    );

    $this->actingAsCredential($credential)
        ->postJson('/ingest', $envelope->toArray())
        ->assertStatus(202);

    $events = RawEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('app')->unique()->values()->all())->toBe(['checkout'])
        ->and($events->pluck('app')->all())->not->toContain('forged-app')
        ->and($events->pluck('record_type')->all())->toBe(['query', 'request']);
});

it('adds execution enrichment columns without losing populated legacy raw events', function (): void {
    Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
        $table->dropColumn([
            'actor',
            'ran_queries',
            'response',
            'user_agent',
            'client_ip',
            'asn',
        ]);
    });

    DB::connection('hone')->table('raw_events')->insert([
        'app' => 'legacy-app',
        'record_type' => 'request',
        'deploy' => null,
        'occurred_at' => '2026-06-09T12:00:00+00:00',
        'normalized_key' => 'GET /legacy',
        'payload' => json_encode(['t' => 'request', 'route_path' => '/legacy'], JSON_THROW_ON_ERROR),
    ]);

    $migration = require __DIR__.'/../database/migrations/2026_09_23_124046_add_execution_enrichment_to_raw_events_table.php';
    $migration->up();

    $event = DB::connection('hone')->table('raw_events')->sole();

    expect(Schema::connection('hone')->hasColumns('raw_events', [
        'actor',
        'ran_queries',
        'response',
        'user_agent',
        'client_ip',
        'asn',
    ]))->toBeTrue()
        ->and($event->app)->toBe('legacy-app')
        ->and(json_decode((string) $event->payload, true, flags: JSON_THROW_ON_ERROR))->toBe([
            't' => 'request',
            'route_path' => '/legacy',
        ])
        ->and($event->actor)->toBeNull()
        ->and($event->ran_queries)->toBeNull()
        ->and($event->response)->toBeNull()
        ->and($event->user_agent)->toBeNull()
        ->and($event->client_ip)->toBeNull()
        ->and($event->asn)->toBeNull();
});

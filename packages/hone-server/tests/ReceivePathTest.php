<?php

declare(strict_types=1);

use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\AppRegistry;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('hone-server.apps', [
        ['id' => 'checkout', 'token_hash' => hash('sha256', 'secret-token')],
        ['id' => 'billing', 'token_hash' => hash('sha256', 'billing-token')],
    ]);
});

it('resolves bearer tokens to registered app ids', function (): void {
    $registry = new AppRegistry;

    expect($registry->resolve('secret-token'))->toBe('checkout')
        ->and($registry->resolve('wrong'))->toBeNull();
});

it('accepts a valid envelope and dispatches the telemetry batch without writing synchronously', function (): void {
    Bus::fake();

    $envelope = Envelope::make(
        app: 'forged-app',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select * from users'],
        ],
    );

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->postJson('/ingest', $envelope->toArray())
        ->assertStatus(202);

    Bus::assertDispatched(ProcessTelemetryBatch::class, function (ProcessTelemetryBatch $job): bool {
        return $job->app === 'checkout'
            && $job->deploy === 'abc123'
            && $job->sentAt === '2026-06-09T12:00:00+00:00'
            && $job->records === [['t' => 'query', 'sql' => 'select * from users']];
    });
    expect(RawEvent::query()->count())->toBe(0);
});

it('rejects unknown bearer tokens without dispatching', function (): void {
    Bus::fake();

    $this->withHeader('Authorization', 'Bearer wrong-token')
        ->postJson('/ingest', Envelope::make('checkout', null, '2026-06-09T12:00:00+00:00', [])->toArray())
        ->assertStatus(401);

    Bus::assertNotDispatched(ProcessTelemetryBatch::class);
});

it('rejects newer envelope versions with an upgrade message without dispatching', function (): void {
    Bus::fake();

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->postJson('/ingest', [
            'envelope_version' => Envelope::VERSION + 1,
            'app' => 'checkout',
            'records' => [],
        ])
        ->assertStatus(422)
        ->assertSee('Upgrade your Hone app');

    Bus::assertNotDispatched(ProcessTelemetryBatch::class);
});

it('rejects malformed envelope bodies without dispatching', function (): void {
    Bus::fake();

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->postJson('/ingest', ['nope' => true])
        ->assertStatus(422);

    Bus::assertNotDispatched(ProcessTelemetryBatch::class);
});

it('reports supported envelope capabilities without authentication', function (): void {
    $this->getJson('/capabilities')
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', Envelope::VERSION)
        ->assertJsonPath('envelope.supported_majors', range(1, Envelope::VERSION));
});

it('processes telemetry batches into raw events', function (): void {
    $job = new ProcessTelemetryBatch(
        app: 'checkout',
        deploy: 'abc123',
        sentAt: '2026-06-09T12:00:00+00:00',
        records: [
            ['t' => 'query', 'sql' => 'select * from users where id = ?', 'duration_ms' => 12, 'timestamp' => '2026-06-09T12:00:01+00:00'],
            ['t' => 'request', 'method' => 'GET', 'route' => '/', 'duration_ms' => 34, 'ts' => 1781006402000],
        ],
    );

    $job->handle();

    $events = RawEvent::query()->orderBy('id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('record_type')->all())->toBe(['query', 'request'])
        ->and($events->pluck('app')->unique()->values()->all())->toBe(['checkout'])
        ->and($events->pluck('deploy')->unique()->values()->all())->toBe(['abc123'])
        ->and($events[0]->normalized_key)->toBe('select * from users where id = ?')
        ->and($events[1]->normalized_key)->toBe('GET /')
        ->and($events[0]->payload)->toEqual(['t' => 'query', 'sql' => 'select * from users where id = ?', 'duration_ms' => 12, 'timestamp' => '2026-06-09T12:00:01+00:00'])
        ->and($events[1]->payload)->toEqual(['t' => 'request', 'method' => 'GET', 'route' => '/', 'duration_ms' => 34, 'ts' => 1781006402000])
        ->and($events[0]->occurred_at)->not->toBeNull()
        ->and($events[1]->occurred_at)->not->toBeNull();
});

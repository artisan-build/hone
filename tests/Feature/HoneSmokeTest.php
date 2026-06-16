<?php

use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('boots the headless Hone app root route', function (): void {
    $this->getJson(route('home'))
        ->assertOk()
        ->assertJson([
            'name' => 'Hone',
            'status' => 'ok',
        ]);
});

it('exposes Hone envelope capabilities', function (): void {
    $this->getJson(route('hone-server.capabilities'))
        ->assertOk()
        ->assertJsonPath('envelope.min_major', 1)
        ->assertJsonPath('envelope.max_major', Envelope::VERSION)
        ->assertJsonPath('envelope.supported_majors', range(1, Envelope::VERSION));
});

it('rejects ingest requests without a valid source token', function (): void {
    $this->postJson(route('hone-server.ingest'), [])
        ->assertUnauthorized();
});

it('registers the web MCP route behind bearer authentication', function (): void {
    $this->postJson(config('hone-server.mcp.path', '/mcp'), [])
        ->assertUnauthorized();
});

it('registers Hone commands and schedules maintenance', function (): void {
    expect(Artisan::all())->toHaveKeys([
        'token:create',
        'hone:maintain',
        'hone:rollup',
        'hone:prune',
    ]);

    $commands = collect(resolve(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->values();

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'hone:maintain')))->toBeTrue();
});

it('runs Hone Postgres migrations and persists raw events on the hone connection', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    expect(Schema::connection('hone')->hasTable('raw_events'))->toBeTrue()
        ->and(Schema::connection('hone')->hasTable('aggregates'))->toBeTrue()
        ->and(Schema::connection('hone')->hasTable('samples'))->toBeTrue();

    $event = RawEvent::query()->create([
        'app' => 'checkout',
        'record_type' => 'request',
        'deploy' => 'abc123',
        'occurred_at' => now(),
        'normalized_key' => 'GET /health',
        'payload' => ['method' => 'GET', 'route' => '/health'],
    ]);

    expect(RawEvent::query()->find($event->getKey()))
        ->not->toBeNull()
        ->payload->toMatchArray(['method' => 'GET', 'route' => '/health']);
});

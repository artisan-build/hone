<?php

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

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
        'hone:issue-token',
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

<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\DeploysTool;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RecordTypesTool;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Illuminate\Support\Carbon;

it('lists apps reporting to hone', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
    ]);

    HoneMcpServer::tool(ListAppsTool::class)
        ->assertOk()
        ->assertSee('checkout');
});

it('lists record types with counts', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
    ]);

    HoneMcpServer::tool(RecordTypesTool::class)
        ->assertOk()
        ->assertSee('query');
});

it('lists recent deploys with first and last seen timestamps', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'deploy' => 'abc123',
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
    ]);

    HoneMcpServer::tool(DeploysTool::class)
        ->assertOk()
        ->assertSee('abc123');
});

it('lists ingest freshness by app', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
    ]);

    HoneMcpServer::tool(IngestFreshnessTool::class)
        ->assertOk()
        ->assertSee('checkout');
});

it('reflects the latest occurred at timestamp for ingest freshness', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'occurred_at' => Carbon::parse('2026-06-08 12:00:00', 'UTC'),
    ]);
    RawEvent::factory()->create([
        'app' => 'checkout',
        'occurred_at' => Carbon::parse('2026-06-09 14:30:00', 'UTC'),
    ]);

    HoneMcpServer::tool(IngestFreshnessTool::class)
        ->assertOk()
        ->assertSee('2026-06-09T12:30:00.000000Z');
});

it('gates the web mcp endpoint with a configured bearer token', function (?string $configuredToken, ?string $presentedToken, bool $authorized): void {
    config()->set('hone-server.mcp.token', $configuredToken);

    $request = $this->postJson((string) config('hone-server.mcp.path'), [], $presentedToken === null ? [] : [
        'Authorization' => 'Bearer '.$presentedToken,
    ]);

    if ($authorized) {
        expect($request->getStatusCode())->not->toBe(401);

        return;
    }

    $request->assertUnauthorized();
})->with([
    'no configured token fails closed' => [null, 'secret-token', false],
    'missing bearer token' => ['secret-token', null, false],
    'wrong bearer token' => ['secret-token', 'wrong', false],
    'matching bearer token' => ['secret-token', 'secret-token', true],
]);

it('scopes record types to the requested app', function (): void {
    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
    ]);
    RawEvent::factory()->create([
        'app' => 'billing',
        'record_type' => 'exception',
    ]);

    HoneMcpServer::tool(RecordTypesTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('query')
        ->assertDontSee('exception');
});

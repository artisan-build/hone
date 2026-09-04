<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\ApiToken;
use ArtisanBuild\BuiltForCloud\Console\AssertionBurn;
use ArtisanBuild\BuiltForCloud\Console\ConsoleSession;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\TokenRegistry;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\DeploysTool;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RecordTypesTool;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

it('loads the framework migrations required for delegated assertions', function (): void {
    expect(Schema::hasTable('bfc_delegated_actors'))->toBeTrue()
        ->and(Schema::hasTable('bfc_console_assertion_burns'))->toBeTrue();
});

it('conforms every advertised tool to the delegated MCP contract', function (): void {
    McpDelegatedTools::assertConforms(HoneMcpServer::class);
});

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
        ->assertSee('2026-06-09T14:30:00.000000Z');
});

it('fails closed for unauthenticated web mcp requests', function (?string $presentedToken): void {
    config()->set('built-for-cloud.fallback_token', null);

    $request = $this->postJson((string) config('hone-server.mcp.path'), [], $presentedToken === null ? [] : [
        'Authorization' => 'Bearer '.$presentedToken,
    ]);

    $request->assertUnauthorized();
})->with([
    'missing bearer token' => [null],
    'unknown bearer token' => ['wrong-token'],
]);

it('accepts an authenticated web mcp initialize request with a database token', function (): void {
    ApiToken::factory()->create(['name' => 'demo', 'token_hash' => hash('sha256', 'secret-token')]);

    $this->postJson((string) config('hone-server.mcp.path'), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'hone-test',
                'version' => '1.0.0',
            ],
        ],
    ], [
        'Authorization' => 'Bearer secret-token',
    ])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Hone');
});

it('returns a real tool result for an MCP-purpose delegated assertion', function (): void {
    RawEvent::factory()->create(['app' => 'checkout']);

    $response = $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion(),
    ]);

    $response->assertOk();
    $result = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('apps')
        ->and($result['apps'])->toHaveCount(1)
        ->and($result['apps'][0]['app'])->toBe('checkout')
        ->and($result['apps'][0]['last_seen'])->toBeString();
});

it('refuses the same delegated assertion when it is replayed', function (): void {
    $assertion = honeMcpAssertion();
    $headers = ['Authorization' => 'Bearer '.$assertion];

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), $headers)
        ->assertOk();

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), $headers)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(AssertionBurn::query()->count())->toBe(1);
});

it('refuses a console-entry assertion on the MCP route', function (): void {
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion(['purpose' => 'console-entry']),
    ])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(AssertionBurn::query()->count())->toBe(0);
});

it('returns the same real tool result for a TokenRegistry bearer', function (): void {
    ApiToken::factory()->create(['name' => 'demo', 'token_hash' => hash('sha256', 'secret-token')]);
    RawEvent::factory()->create(['app' => 'checkout']);

    $response = $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer secret-token',
    ]);

    $response->assertOk();
    $result = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('apps')
        ->and($result['apps'])->toHaveCount(1)
        ->and($result['apps'][0]['app'])->toBe('checkout')
        ->and($result['apps'][0]['last_seen'])->toBeString();
});

it('writes no session key while authenticating a delegated assertion', function (): void {
    $this->withSession(['sentinel' => 'kept'])
        ->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
            'Authorization' => 'Bearer '.honeMcpAssertion(),
        ])
        ->assertOk();

    $session = session()->all();

    expect($session['sentinel'] ?? null)->toBe('kept')
        ->and(collect(array_keys($session))->contains(
            fn (string $key): bool => str_starts_with($key, 'login_bfc-console_'),
        ))->toBeFalse();

    foreach (ConsoleSession::keys() as $key) {
        expect($session)->not->toHaveKey($key);
    }
});

it('denies the fallback token for web mcp requests', function (): void {
    config()->set('built-for-cloud.fallback_token', 'fallback-secret');

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => 'Bearer fallback-secret',
    ])->assertUnauthorized();
});

it('denies an expired token for web mcp requests', function (): void {
    ApiToken::factory()->create([
        'name' => 'demo',
        'token_hash' => hash('sha256', 'secret-token'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => 'Bearer secret-token',
    ])->assertUnauthorized();
});

it('denies a revoked token for web mcp requests', function (): void {
    ApiToken::factory()->create(['name' => 'demo', 'token_hash' => hash('sha256', 'secret-token')]);

    app(TokenRegistry::class)->revoke('demo');

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => 'Bearer secret-token',
    ])->assertUnauthorized();
});

it('does not expose unauthenticated non post mcp methods as a data path', function (string $method): void {
    ApiToken::factory()->create(['name' => 'demo', 'token_hash' => hash('sha256', 'secret-token')]);

    // Laravel MCP registers inert GET/DELETE responders for method negotiation; they must not return data.
    $response = $this->json($method, (string) config('hone-server.mcp.path'));

    expect($response->getStatusCode())->toBeIn([401, 405]);
})->with([
    'GET' => ['GET'],
    'DELETE' => ['DELETE'],
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

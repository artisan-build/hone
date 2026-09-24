<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\AuditActorType;
use ArtisanBuild\BuiltForCloud\Console\AssertionBurn;
use ArtisanBuild\BuiltForCloud\Console\ConsoleEntryRefusalReason;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\DeploysTool;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ListAppsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RecordTypesTool;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;

uses(WithCredentials::class);

it('loads the framework migrations required for delegated assertions', function (): void {
    expect(Schema::hasTable('bfc_delegated_actors'))->toBeTrue()
        ->and(Schema::hasTable('bfc_console_assertion_burns'))->toBeTrue();
});

it('conforms every advertised tool to the delegated MCP contract', function (): void {
    McpDelegatedTools::assertConforms(HoneMcpServer::class);
});

it('conforms to the package MCP product admission contract', function (): void {
    McpProductAdmission::assert();
});

it('couples a non-default HONE_MCP_PATH to metadata and the guarded route', function (): void {
    expect(config('hone-server.mcp.path'))->toBe('/custom-mcp')
        ->and(config('built-for-cloud.mcp.path'))->toBe('/custom-mcp');

    $this->getJson('/bfc/meta')
        ->assertOk()
        ->assertJsonPath('endpoints.mcp', '/custom-mcp');

    $route = Route::getRoutes()->match(Request::create('/custom-mcp', 'POST'));

    expect(resolve('router')->gatherRouteMiddleware($route))->toContain(AuthenticateMcp::class.':product');

    $this->postJson('/custom-mcp')->assertUnauthorized();
});

it('registers no local stdio MCP handle', function (): void {
    $registrar = app(Registrar::class);

    $localServers = new ReflectionProperty($registrar, 'localServers');

    expect($localServers->getValue($registrar))->toBe([])
        ->and(Mcp::getWebServer(ltrim((string) config('hone-server.mcp.path'), '/')))->not->toBeNull();
});

it('lists apps reporting to hone', function (): void {
    Carbon::setTestNow('2026-06-09 15:00:00+00');

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
    Carbon::setTestNow('2026-06-09 15:00:00+00');

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
    Carbon::setTestNow('2026-06-09 15:00:00+00');

    RawEvent::factory()->create([
        'app' => 'checkout',
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
    ]);

    HoneMcpServer::tool(IngestFreshnessTool::class)
        ->assertOk()
        ->assertSee('checkout');
});

it('reflects the latest occurred at timestamp for ingest freshness', function (): void {
    Carbon::setTestNow('2026-06-09 15:00:00+00');

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

it('accepts an authenticated web mcp initialize request with an installation-owned package credential', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'hone-mcp-automation',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);

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
        'Authorization' => $credential->bearerHeader(),
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
    $mintId = 'mint_replay_test';
    $assertion = honeMcpAssertion(['jti' => $mintId]);
    $headers = ['Authorization' => 'Bearer '.$assertion];

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), $headers)
        ->assertOk();

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), $headers)
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $burn = AssertionBurn::query()->sole();
    $audit = CredentialAuditEvent::query()
        ->where('event', LifecycleEventType::DeniedAction)
        ->sole();

    expect($burn->mint_id)->toBe($mintId)
        ->and($burn->issuer)->toBe('https://scalpels.test')
        ->and($burn->redeemed_at)->not->toBeNull()
        ->and($audit->event)->toBe(LifecycleEventType::DeniedAction)
        ->and($audit->actor_type)->toBe(AuditActorType::CredentialHolder)
        ->and($audit->actor_ref)->toBe($mintId)
        ->and($audit->note)->toBe(AuthenticateMcp::AUDIT_NOTE.ConsoleEntryRefusalReason::Replayed->value);
});

it('refuses an assertion without the MCP purpose on the MCP route', function (mixed $purpose): void {
    $mintId = 'mint_purpose_test';

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion([
            'jti' => $mintId,
            'purpose' => $purpose,
        ]),
    ])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    $audit = CredentialAuditEvent::query()
        ->where('event', LifecycleEventType::DeniedAction)
        ->sole();

    expect(AssertionBurn::query()->count())->toBe(0)
        ->and($audit->event)->toBe(LifecycleEventType::DeniedAction)
        ->and($audit->actor_type)->toBe(AuditActorType::CredentialHolder)
        ->and($audit->actor_ref)->toBe($mintId)
        ->and($audit->note)->toBe(AuthenticateMcp::AUDIT_NOTE.ConsoleEntryRefusalReason::PurposeMismatch->value);
})->with([
    'console-entry purpose' => 'console-entry',
    'absent purpose' => honeMcpAbsent(),
]);

it('uniformly refuses assertions for another installation and malformed or failed assertions', function (): void {
    $valid = honeMcpAssertion();
    $segments = explode('.', $valid);
    expect($segments)->toHaveCount(4);
    $lastPayloadCharacter = substr($segments[2], -1);
    $segments[2] = substr($segments[2], 0, -1).($lastPayloadCharacter === 'A' ? 'B' : 'A');
    $foreign = honeMcpSigningKey();

    $responses = [
        $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
            'Authorization' => 'Bearer '.honeMcpAssertion(['aud' => 'https://another-installation.test']),
        ]),
        $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
            'Authorization' => 'Bearer v4.public.not-a-valid-assertion',
        ]),
        $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
            'Authorization' => 'Bearer '.implode('.', $segments),
        ]),
        $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
            'Authorization' => 'Bearer '.honeMcpAssertion([], 'unknown-key', $foreign),
        ]),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        expect($response->getContent())->toBe($responses[0]->getContent());
    }
});

it('keeps both assertion keys valid during rotation and refuses the retired key', function (): void {
    $previous = honeMcpTestSigningKey();
    $current = honeMcpSigningKey();
    $keyring = new ConsoleKeyring;
    $keyring->add('hone-current-key', $current->getPublicKey()->toHexString());
    $keyring->activate('hone-current-key');

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion([], 'hone-test-key', $previous),
    ])->assertOk();
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion([], 'hone-current-key', $current),
    ])->assertOk();

    $keyring->retire('hone-test-key');

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion([], 'hone-test-key', $previous),
    ])->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion([], 'hone-current-key', $current),
    ])->assertOk();
});

it('returns the same real tool result for an installation-owned package credential', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'hone-mcp-automation',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    RawEvent::factory()->create(['app' => 'checkout']);

    $response = $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => $credential->bearerHeader(),
    ]);

    $response->assertOk();
    $result = json_decode((string) $response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);

    expect($result)->toHaveKey('apps')
        ->and($result['apps'])->toHaveCount(1)
        ->and($result['apps'][0]['app'])->toBe('checkout')
        ->and($result['apps'][0]['last_seen'])->toBeString();
});

it('writes no session key while authenticating a delegated assertion', function (): void {
    $this->withSession(['sentinel' => 'kept']);

    $before = session()->all();

    $response = $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.honeMcpAssertion(),
    ])
        ->assertOk();

    $after = session()->all();

    expect(config('session.driver'))->toBe('array')
        ->and(array_keys($before))->toBe(['_token', 'sentinel'])
        ->and($after)->toBe($before)
        ->and(collect(array_keys($after))->contains(
            fn (string $key): bool => str_starts_with($key, 'login_bfc-console_'),
        ))->toBeFalse();

    $response->assertHeaderMissing('Set-Cookie');
});

it('never falls through from an assertion-shaped bearer to a resolving unified credential', function (): void {
    $collidingToken = 'v4.public.registry-collision';

    Credential::query()->create([
        'name' => 'collision',
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'assertion-collision',
        'abilities' => [OperatorAbility::McpRead->value],
        'secret_hash' => hash('sha256', $collidingToken),
    ]);
    RawEvent::factory()->create(['app' => 'checkout']);

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer '.$collidingToken,
    ])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

it('never falls through from a failed unified credential bearer to assertion verification', function (): void {
    $before = CredentialAuditEvent::query()->count();

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpToolCall(), [
        'Authorization' => 'Bearer unknown-registry-token',
    ])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    expect(CredentialAuditEvent::query()->count())->toBe($before);
});

it('denies the fallback token for web mcp requests', function (): void {
    config()->set('built-for-cloud.fallback_token', 'fallback-secret');

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => 'Bearer fallback-secret',
    ])->assertUnauthorized();
});

it('denies an expired credential for web mcp requests', function (): void {
    $credential = $this->mintCredential([
        'name' => 'demo',
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'expired-mcp',
        'abilities' => [OperatorAbility::McpRead->value],
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => $credential->bearerHeader(),
    ])->assertUnauthorized();
});

it('denies a revoked credential for web mcp requests', function (): void {
    $credential = $this->mintCredential([
        'name' => 'demo',
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'revoked-mcp',
        'abilities' => [OperatorAbility::McpRead->value],
    ]);
    app(RevokeCredential::class)($credential->credential->id);

    $this->postJson((string) config('hone-server.mcp.path'), [], [
        'Authorization' => $credential->bearerHeader(),
    ])->assertUnauthorized();
});

it('does not expose unauthenticated non post mcp methods as a data path', function (string $method): void {
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

it('bounds every raw event catalogue tool to a finite default lookback', function (string $toolClass): void {
    Carbon::setTestNow('2026-06-09 15:00:00+00');

    RawEvent::factory()->create(['app' => 'recent-app', 'record_type' => 'recent-type', 'deploy' => 'recent-deploy', 'occurred_at' => now()->subHours(71)]);
    RawEvent::factory()->create(['app' => 'ancient-app', 'record_type' => 'ancient-type', 'deploy' => 'ancient-deploy', 'occurred_at' => now()->subHours(73)]);

    DB::connection('hone')->enableQueryLog();
    $response = HoneMcpServer::tool($toolClass)->assertOk();
    $rawEventReads = collect(DB::connection('hone')->getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'from "raw_events"'));
    DB::connection('hone')->disableQueryLog();

    expect($rawEventReads)->not->toBeEmpty();

    $catalogueRead = $rawEventReads->first(fn (array $entry): bool => str_contains($entry['query'], 'group by'));

    expect($catalogueRead['query'])->toContain('"occurred_at" >= ?')
        ->and(collect($catalogueRead['bindings'])->contains(fn (mixed $binding): bool => $binding instanceof DateTimeInterface
            && CarbonImmutable::instance($binding)->equalTo(CarbonImmutable::parse('2026-06-06 15:00:00+00'))))->toBeTrue()
        ->and(honeToolPayload($response)['window'])->toBe(['hours' => 72, 'since' => '2026-06-06T15:00:00.000000Z']);

    $response->assertDontSee('ancient-')->assertSee('recent-');
})->with([
    ListAppsTool::class,
    RecordTypesTool::class,
    DeploysTool::class,
    IngestFreshnessTool::class,
]);

it('honors an explicit raw event lookback and rejects an unbounded one', function (string $toolClass): void {
    Carbon::setTestNow('2026-06-09 15:00:00+00');

    RawEvent::factory()->create(['app' => 'older-app', 'record_type' => 'older-type', 'deploy' => 'older-deploy', 'occurred_at' => now()->subHours(100)]);

    HoneMcpServer::tool($toolClass, ['hours' => 101])->assertOk()->assertSee('older-');
    HoneMcpServer::tool($toolClass, ['hours' => 99])->assertOk()->assertDontSee('older-');
    HoneMcpServer::tool($toolClass, ['hours' => 721])->assertHasErrors();
})->with([
    ListAppsTool::class,
    RecordTypesTool::class,
    DeploysTool::class,
    IngestFreshnessTool::class,
]);

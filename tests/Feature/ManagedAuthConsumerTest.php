<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Auth\CredentialResolver;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\ManagedAuthClient;
use ArtisanBuild\BuiltForCloud\ManagedTransitionClient;
use ArtisanBuild\BuiltForCloud\ManagedTransitionDirection;
use ArtisanBuild\BuiltForCloud\ManagedTransitions;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\HoneContracts\Envelope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function configureManagedHoneAuthority(int $generation = 7): void
{
    DB::table('bfc_authority')->updateOrInsert(
        ['key' => InstallationAuthority::KEY],
        [
            'mode' => AuthorityMode::Managed->value,
            'generation' => $generation,
            'issuer' => 'https://issuer.example.test',
            'connection_id' => 'hone-connection',
            'organization_id' => 'hone-organization',
            'installation_id' => 'hone-installation',
            'authority_base_url' => 'https://authority.example.test',
            'managed_connection_status' => 'active',
            'managed_connection_generation' => $generation,
            'managed_connection_roster_version' => 100,
            'managed_connection_response_sequence' => 100,
            'updated_at' => now(),
        ],
    );
    config()->set('built-for-cloud.managed.client_secret', 'managed-client-secret');
}

function managedHoneUser(string $role = 'admin', int $generation = 7, int $counter = 100): User
{
    $user = createBuiltForCloudUser([
        'name' => 'Managed Operator',
        'email' => 'managed-operator@example.test',
    ]);
    $user->forceFill([
        'role' => $role,
        'status' => 'active',
        'email_verified_at' => now(),
        'scalpels_issuer' => 'https://issuer.example.test',
        'scalpels_connection_id' => 'hone-connection',
        'scalpels_id' => 'managed-operator',
        'membership_confirmed_at' => now(),
        'membership_checked_at' => now(),
        'membership_response_at' => now(),
        'managed_membership_status' => 'active',
        'managed_membership_role' => $role,
        'managed_membership_generation' => $generation,
        'managed_membership_roster_version' => $counter,
        'managed_membership_response_sequence' => $counter,
        'managed_membership_responded_at' => now(),
    ])->save();

    return $user->refresh();
}

/** @return array{Credential, string} */
function honeBearerCredential(
    string $name,
    CredentialPurpose $purpose,
    SubjectType $subjectType,
    string $subjectRef,
    ?User $user = null,
): array {
    $secret = $name.'-secret';
    $credential = Credential::query()->create([
        'name' => $name,
        'kind' => CredentialKind::Bearer,
        'purpose' => $purpose,
        'subject_type' => $subjectType,
        'subject_ref' => $subjectRef,
        'user_id' => $user === null ? null : (string) $user->getKey(),
        'secret_hash' => hash('sha256', $secret),
    ]);

    return [$credential, $secret];
}

/** @param array<string, mixed> $overrides */
function fakeHoneManagedConfirmation(array &$overrides): void
{
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use (&$overrides): mixed {
        expect(parse_url($request->url(), PHP_URL_PATH))->toBe('/managed-auth/v1/memberships/confirm');
        $body = $request->data();

        return Http::response(array_merge([
            'contract_version' => ManagedAuthClient::CONTRACT_VERSION,
            'issuer' => 'https://issuer.example.test',
            'connection_id' => 'hone-connection',
            'organization_id' => 'hone-organization',
            'installation_id' => 'hone-installation',
            'authority_generation' => 7,
            'scalpels_id' => $body['scalpels_id'],
            'membership_status' => 'active',
            'connection_status' => 'active',
            'role' => 'member',
            'roster_version' => 101,
            'response_sequence' => 101,
            'responded_at' => now()->toAtomString(),
        ], $overrides));
    });
}

/** @return array<string, mixed> */
function honeMcpListAppsCall(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list-apps-tool',
            'arguments' => [],
        ],
    ];
}

it('refreshes a stale managed MCP role through the real Hone product route', function (): void {
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
    configureManagedHoneAuthority();
    $user = managedHoneUser();
    [$credential, $secret] = honeBearerCredential(
        'managed-mcp',
        CredentialPurpose::Mcp,
        SubjectType::UserPrincipal,
        'managed-operator',
        $user,
    );
    $identity = $user->only(['id', 'name', 'email', 'scalpels_issuer', 'scalpels_connection_id', 'scalpels_id']);
    $confirmation = [];
    fakeHoneManagedConfirmation($confirmation);
    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');

    $this->postJson((string) config('hone-server.mcp.path'), honeMcpListAppsCall(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertOk();

    $refreshed = $user->refresh();
    expect($credential->refresh()->last_used_at)->not->toBeNull()
        ->and($refreshed->only(array_keys($identity)))->toBe($identity)
        ->and($refreshed->role)->toBe('member')
        ->and($refreshed->managed_membership_role)->toBe('member')
        ->and($refreshed->managed_membership_generation)->toBe(7)
        ->and($refreshed->managed_membership_roster_version)->toBe(101)
        ->and($refreshed->managed_membership_response_sequence)->toBe(101);
});

it('orders managed confirmations by authority generation through the real Hone route', function (): void {
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
    configureManagedHoneAuthority();
    $user = managedHoneUser();
    [, $secret] = honeBearerCredential(
        'ordered-mcp',
        CredentialPurpose::Mcp,
        SubjectType::UserPrincipal,
        'managed-operator',
        $user,
    );
    $identity = $user->only(['id', 'name', 'email', 'scalpels_id']);
    $confirmation = [
        'role' => 'owner',
        'roster_version' => 1,
        'response_sequence' => 1,
    ];
    fakeHoneManagedConfirmation($confirmation);

    CarbonImmutable::setTestNow('2026-09-15T12:05:00+00:00');
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpListAppsCall(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertOk();
    expect($user->refresh()->role)->toBe('admin')
        ->and($user->managed_membership_roster_version)->toBe(100)
        ->and($user->managed_membership_response_sequence)->toBe(100);

    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update(['generation' => 8]);
    $confirmation = [
        'authority_generation' => 8,
        'role' => 'owner',
        'roster_version' => 1,
        'response_sequence' => 1,
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:10:00+00:00');
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpListAppsCall(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertOk();
    expect($user->refresh()->role)->toBe('owner')
        ->and($user->managed_membership_generation)->toBe(8)
        ->and($user->managed_membership_roster_version)->toBe(1)
        ->and($user->managed_membership_response_sequence)->toBe(1);

    $confirmation = [
        'authority_generation' => 7,
        'role' => 'member',
        'roster_version' => 999,
        'response_sequence' => 999,
    ];
    CarbonImmutable::setTestNow('2026-09-15T12:15:00+00:00');
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpListAppsCall(), [
        'Authorization' => 'Bearer '.$secret,
    ])->assertOk();

    $unchanged = $user->refresh();
    expect($unchanged->only(array_keys($identity)))->toBe($identity)
        ->and($unchanged->role)->toBe('owner')
        ->and($unchanged->managed_membership_role)->toBe('owner')
        ->and($unchanged->managed_membership_generation)->toBe(8)
        ->and($unchanged->managed_membership_roster_version)->toBe(1)
        ->and($unchanged->managed_membership_response_sequence)->toBe(1);
});

it('contains account authority while installation-owned Hone credentials survive a managed exit', function (): void {
    CarbonImmutable::setTestNow('2026-09-15T12:00:00+00:00');
    configureManagedHoneAuthority();
    config()->set([
        'session.driver' => 'database',
        'session.table' => 'sessions',
    ]);
    $owner = managedHoneUser('owner');
    $owner->forceFill([
        'password' => Hash::make('standalone-password'),
        'original_contact_email' => $owner->email,
    ])->save();
    [$accountCredential, $accountSecret] = honeBearerCredential(
        'account-mcp',
        CredentialPurpose::Mcp,
        SubjectType::UserPrincipal,
        'managed-operator',
        $owner,
    );
    [$ingestCredential, $ingestSecret] = honeBearerCredential(
        'hone-ingest',
        CredentialPurpose::Consumption,
        SubjectType::Installation,
        'checkout',
    );
    [$mcpCredential, $mcpSecret] = honeBearerCredential(
        'hone-mcp',
        CredentialPurpose::Mcp,
        SubjectType::Installation,
        'hone-installation',
    );
    DB::table('sessions')->insert([
        'id' => 'managed-owner-session',
        'user_id' => $owner->getKey(),
        'payload' => 'managed owner session',
        'last_activity' => now()->getTimestamp(),
    ]);
    $calls = [];
    Http::preventStrayRequests();
    Http::fake(function (ClientRequest $request) use (&$calls): mixed {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $body = $request->data();
        $calls[] = $path;
        $binding = [
            'contract_version' => ManagedTransitionClient::CONTRACT_VERSION,
            'issuer' => 'https://issuer.example.test',
            'connection_id' => 'hone-connection',
            'organization_id' => 'hone-organization',
            'installation_id' => 'hone-installation',
            'authority_generation' => 7,
            'roster_version' => 41,
            'response_sequence' => 73,
            'responded_at' => now()->toAtomString(),
        ];

        return match (true) {
            $path === '/managed-transition/v1/transitions' => Http::response(array_merge($binding, [
                'transition_request_id' => $body['transition_request_id'],
                'transition_id' => 'hone-exit-transition',
                'direction' => ManagedTransitionDirection::Exit->value,
                'status' => 'prepared',
                'roster_cutoff_at' => '2026-09-15T12:00:00+00:00',
                'roster_total' => 1,
            ])),
            str_ends_with($path, '/roster') => Http::response(array_merge($binding, [
                'transition_id' => 'hone-exit-transition',
                'roster_cutoff_at' => '2026-09-15T12:00:00+00:00',
                'members' => [[
                    'scalpels_id' => 'managed-operator',
                    'membership_status' => 'active',
                    'role' => 'owner',
                    'display_name' => 'Managed Operator',
                    'contact_email' => 'managed-operator@example.test',
                    'contact_email_verified' => true,
                ]],
                'next_cursor' => null,
                'page_total' => 1,
            ])),
            str_ends_with($path, '/stage') => Http::response(array_merge($binding, [
                'transition_id' => 'hone-exit-transition',
                'status' => 'staged',
                'roster_cutoff_at' => '2026-09-15T12:00:00+00:00',
            ])),
            str_ends_with($path, '/ack') => Http::response(array_merge($binding, [
                'authority_generation' => 8,
                'transition_id' => 'hone-exit-transition',
                'status' => 'acknowledged',
                'generation_after' => 8,
                'local_commit_receipt' => $body['local_commit_receipt'],
                'acknowledged_at' => now()->toAtomString(),
            ])),
            default => Http::response([], 500),
        };
    });

    $transitions = app(ManagedTransitions::class);
    $transition = $transitions->prepare($owner, ManagedTransitionDirection::Exit);
    $transition = $transitions->fetchRoster($transition);
    $transition = $transitions->propose($transition, [[
        'scalpels_id' => 'managed-operator',
        'local_kind' => 'user',
        'local_id' => (string) $owner->getKey(),
        'role' => 'owner',
        'disposition' => 'link',
        'final_email' => 'managed-operator@example.test',
    ]]);
    $completed = $transitions->complete($owner, $transition);

    expect($completed->status->value)->toBe('acknowledged')
        ->and(InstallationAuthority::current()->mode)->toBe(AuthorityMode::Standalone)
        ->and($calls)->toHaveCount(4)
        ->and($accountCredential->refresh()->revoked_at)->not->toBeNull()
        ->and($ingestCredential->refresh()->revoked_at)->toBeNull()
        ->and($mcpCredential->refresh()->revoked_at)->toBeNull()
        ->and(DB::table('sessions')->where('id', 'managed-owner-session')->exists())->toBeFalse()
        ->and(app(CredentialResolver::class)->resolve(CredentialKind::Bearer, $accountSecret))->toBeNull()
        ->and(app(CredentialResolver::class)->resolve(CredentialKind::Bearer, $ingestSecret)?->id)->toBe($ingestCredential->id)
        ->and(app(CredentialResolver::class)->resolve(CredentialKind::Bearer, $mcpSecret)?->id)->toBe($mcpCredential->id);

    Queue::fake();
    $this->withHeader('Authorization', 'Bearer '.$ingestSecret)
        ->postJson('/ingest', Envelope::make('forged-app', null, now()->toAtomString(), [])->toArray())
        ->assertAccepted();
    $this->postJson((string) config('hone-server.mcp.path'), honeMcpListAppsCall(), [
        'Authorization' => 'Bearer '.$mcpSecret,
    ])->assertOk();
});

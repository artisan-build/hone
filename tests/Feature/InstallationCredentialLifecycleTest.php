<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\SystemAuthorityContext;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('auth.guards.bfc', [
        'driver' => 'bfc',
        'provider' => 'users',
    ]);

    DB::table('bfc_authority')->updateOrInsert(
        ['key' => InstallationAuthority::KEY],
        [
            'mode' => AuthorityMode::Standalone->value,
            'generation' => 1,
            'updated_at' => now(),
        ],
    );

    Route::middleware('auth:bfc')->get('/h1/credential-authority-probe', static fn (): array => [
        'system_authority' => app(SystemAuthorityContext::class)->active(),
    ]);
    Route::middleware('bfc.ability:'.OperatorAbility::CredentialRead->value)
        ->get('/h1/control-plane-probe', static fn (): array => ['authorized' => true]);
});

function credentialLifecycleUser(UserRole $role, string $label): User
{
    $user = User::query()->create([
        'name' => ucfirst($label),
        'email' => $label.'@example.test',
        'password' => Hash::make('test-password'),
    ]);
    $user->forceFill([
        'role' => $role->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    return $user;
}

function loginCredentialLifecycleUser(User $user): void
{
    test()->post('/bfc/login', [
        'email' => $user->email,
        'password' => 'test-password',
    ])->assertRedirect('/bfc/ui');
}

it('lets every package role perform the complete installation credential lifecycle in Hone', function (UserRole $role): void {
    $member = credentialLifecycleUser($role, 'lifecycle-'.$role->value);
    loginCredentialLifecycleUser($member);

    $issue = $this->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'hone-source-'.$role->value,
        'purpose' => CredentialPurpose::Consumption->value,
        'name' => 'hone-ingest-'.$role->value,
    ])->assertCreated();

    $issued = Credential::query()->findOrFail($issue->json('credential.id'));

    expect($issued->ownership())->toBe(CredentialOwnership::Installation)
        ->and($issued->user_id)->toBeNull()
        ->and($issued->purpose)->toBe(CredentialPurpose::Consumption);

    $this->getJson('/bfc/installation/credentials')
        ->assertOk()
        ->assertJsonFragment(['id' => $issued->id]);

    $rotation = $this->postJson('/bfc/installation/credentials/'.$issued->id.'/rotate')
        ->assertCreated();
    $replacement = Credential::query()->findOrFail($rotation->json('credential.id'));

    expect($issued->refresh()->rotated_at)->not->toBeNull()
        ->and($replacement->ownership())->toBe(CredentialOwnership::Installation)
        ->and($replacement->user_id)->toBeNull()
        ->and($replacement->purpose)->toBe(CredentialPurpose::Consumption);

    $this->deleteJson('/bfc/installation/credentials/'.$replacement->id)->assertNoContent();
    expect($replacement->refresh()->revoked_at)->not->toBeNull();
})->with([
    'Owner' => UserRole::Owner,
    'Admin' => UserRole::Admin,
    'Member' => UserRole::Member,
]);

it('lets one Member manage a package credential issued by another Member', function (): void {
    $issuer = credentialLifecycleUser(UserRole::Member, 'credential-issuer');
    $manager = credentialLifecycleUser(UserRole::Member, 'credential-manager');
    loginCredentialLifecycleUser($issuer);

    $issue = $this->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'cross-member-source',
        'purpose' => CredentialPurpose::Consumption->value,
        'name' => 'hone-ingest-cross-member',
    ])->assertCreated();
    $issued = Credential::query()->findOrFail($issue->json('credential.id'));

    $this->post('/bfc/logout')->assertRedirect('/bfc/login');
    loginCredentialLifecycleUser($manager);
    $this->getJson('/bfc/installation/credentials')
        ->assertOk()
        ->assertJsonFragment(['id' => $issued->id]);

    $rotation = $this->postJson('/bfc/installation/credentials/'.$issued->id.'/rotate')
        ->assertCreated();
    $replacement = Credential::query()->findOrFail($rotation->json('credential.id'));

    expect($issued->refresh()->rotated_at)->not->toBeNull()
        ->and($replacement->user_id)->toBeNull();

    $this->deleteJson('/bfc/installation/credentials/'.$replacement->id)->assertNoContent();
    expect($replacement->refresh()->revoked_at)->not->toBeNull();
});

it('keeps account-bound personal credentials outside the installation management surface', function (): void {
    $member = credentialLifecycleUser(UserRole::Member, 'personal-owner');
    $other = credentialLifecycleUser(UserRole::Member, 'personal-other');
    $personal = Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::UserPrincipal,
        'subject_ref' => 'user:'.$other->getKey(),
        'user_id' => (string) $other->getKey(),
        'secret_hash' => hash('sha256', 'personal-other-secret'),
        'status' => CredentialStatus::Active,
    ]);

    loginCredentialLifecycleUser($member);

    $this->getJson('/bfc/installation/credentials')
        ->assertOk()
        ->assertJsonMissing(['id' => $personal->id]);
    $this->postJson('/bfc/installation/credentials/'.$personal->id.'/rotate')->assertNotFound();
    $this->deleteJson('/bfc/installation/credentials/'.$personal->id)->assertNotFound();

    expect($personal->ownership())->toBe(CredentialOwnership::Account)
        ->and($personal->refresh()->rotated_at)->toBeNull()
        ->and($personal->revoked_at)->toBeNull()
        ->and($personal->user_id)->toBe((string) $other->getKey());
});

it('keeps consumption credentials outside control-plane and system authority', function (): void {
    $member = credentialLifecycleUser(UserRole::Member, 'authority-boundary');
    loginCredentialLifecycleUser($member);
    $before = Credential::query()->count();

    $this->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'authority-boundary-source',
        'purpose' => CredentialPurpose::Consumption->value,
        'abilities' => [OperatorAbility::Admin->value],
        'name' => 'forbidden-control-plane-ingest',
    ])->assertForbidden();
    expect(Credential::query()->count())->toBe($before);

    $issue = $this->postJson('/bfc/installation/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => 'authority-boundary-source',
        'purpose' => CredentialPurpose::Consumption->value,
        'name' => 'hone-ingest-authority-boundary',
    ])->assertCreated();
    $credential = Credential::query()->findOrFail($issue->json('credential.id'));
    $secret = (string) $issue->json('delivery.secret');

    expect($credential->abilities)->toBeNull()
        ->and($credential->purpose)->toBe(CredentialPurpose::Consumption);

    $headers = ['Authorization' => 'Bearer '.$secret];
    $this->getJson('/h1/control-plane-probe', $headers)->assertForbidden();
    $this->getJson('/bfc/credentials', $headers)->assertUnauthorized();
    $this->getJson('/h1/credential-authority-probe', $headers)
        ->assertOk()
        ->assertExactJson(['system_authority' => false]);
});

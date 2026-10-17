<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(WithCredentials::class);

beforeEach(function (): void {
    $this->freezeSecond();
    Queue::fake();
});

/** @return array<string, mixed> */
function ingestCacheEnvelope(): array
{
    return Envelope::make('forged-app', null, now()->toAtomString(), [])->toArray();
}

/** @return array<string, mixed> */
function ingestCacheCredentialAttributes(): array
{
    return [
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ];
}

/** @return object{lookups: int, usageWrites: int, cacheWrites: list<array{key: string, value: mixed}>} */
function watchIngestCredentialBoundaries(): object
{
    $observed = (object) [
        'lookups' => 0,
        'usageWrites' => 0,
        'cacheWrites' => [],
    ];

    DB::listen(function (QueryExecuted $query) use ($observed): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select')
            && str_contains($sql, 'from "credentials"')
            && str_contains($sql, '"secret_hash"')) {
            $observed->lookups++;
        }

        if (str_starts_with($sql, 'update "credentials" set "last_used_at"')) {
            $observed->usageWrites++;
        }
    });

    Event::listen(WritingKey::class, function (WritingKey $event) use ($observed): void {
        if (str_starts_with($event->key, 'hone-server:ingest-credential:')) {
            $observed->cacheWrites[] = [
                'key' => $event->key,
                'value' => $event->value,
            ];
        }
    });

    return $observed;
}

it('loads the ingest credential cache TTL from configuration', function (?string $override, int $expected): void {
    $environment = Env::getRepository();
    $name = 'HONE_INGEST_CREDENTIAL_CACHE_TTL';
    $previous = $environment->get($name);
    $environment->clear($name);

    try {
        if ($override !== null) {
            $environment->set($name, $override);
        }

        $configuration = require __DIR__.'/../config/hone-server.php';

        expect($configuration['ingest']['credential_cache_ttl'])->toBe($expected);
    } finally {
        $environment->clear($name);

        if ($previous !== null) {
            $environment->set($name, $previous);
        }
    }
})->with([
    'default' => [null, 60],
    'override' => ['17', 17],
    'disabled' => ['0', 0],
]);

it('uses a fixed TTL while preserving attribution and secret-free cache data', function (): void {
    config()->set('hone-server.ingest.credential_cache_ttl', 10);
    $minted = $this->mintCredential(ingestCacheCredentialAttributes());
    $observed = watchIngestCredentialBoundaries();

    $this->actingAsCredential($minted)
        ->postJson('/ingest', ingestCacheEnvelope())
        ->assertAccepted();

    $firstUse = $minted->credential->refresh()->last_used_at?->toDateTimeString();

    expect($observed->lookups)->toBe(1)
        ->and($observed->usageWrites)->toBe(1)
        ->and($observed->cacheWrites)->toHaveCount(1);

    $secret = $minted->plaintext();
    $expectedKey = 'hone-server:ingest-credential:'.hash('sha256', $secret);
    $write = $observed->cacheWrites[0];

    expect($write['key'])->toBe($expectedKey)
        ->and(str_contains($write['key'], $secret))->toBeFalse()
        ->and(str_contains(serialize($write['value']), $secret))->toBeFalse()
        ->and($write['value'])->toBe([
            'credential_id' => $minted->credential->id,
            'subject_ref' => 'checkout',
        ]);

    $this->travel(9)->seconds();
    $this->postJson('/ingest', ingestCacheEnvelope())->assertAccepted();

    expect($observed->lookups)->toBe(1)
        ->and($observed->usageWrites)->toBe(1)
        ->and($observed->cacheWrites)->toHaveCount(1)
        ->and($minted->credential->refresh()->last_used_at?->toDateTimeString())->toBe($firstUse);

    $this->travel(1)->seconds();
    $this->postJson('/ingest', ingestCacheEnvelope())->assertAccepted();

    expect($observed->lookups)->toBe(2)
        ->and($observed->usageWrites)->toBe(2)
        ->and($observed->cacheWrites)->toHaveCount(2)
        ->and($minted->credential->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    $jobs = Queue::pushed(ProcessTelemetryBatch::class);

    expect($jobs)->not->toBeEmpty()
        ->and($jobs->every(fn (ProcessTelemetryBatch $job): bool => $job->app === 'checkout'))->toBeTrue();
});

it('bypasses the cache entirely when its TTL is zero', function (): void {
    config()->set('hone-server.ingest.credential_cache_ttl', 0);
    $minted = $this->mintCredential(ingestCacheCredentialAttributes());
    $key = 'hone-server:ingest-credential:'.hash('sha256', $minted->plaintext());
    $planted = [
        'credential_id' => $minted->credential->id,
        'subject_ref' => 'forged-source',
    ];
    Cache::put($key, $planted, 60);
    $observed = watchIngestCredentialBoundaries();

    $this->actingAsCredential($minted)
        ->postJson('/ingest', ingestCacheEnvelope())
        ->assertAccepted();
    $this->travel(1)->seconds();
    $this->postJson('/ingest', ingestCacheEnvelope())->assertAccepted();

    expect($observed->lookups)->toBe(2)
        ->and($observed->usageWrites)->toBe(2)
        ->and($observed->cacheWrites)->toBe([])
        ->and(Cache::get($key))->toBe($planted)
        ->and($minted->credential->refresh()->last_used_at?->toDateTimeString())->toBe(now()->toDateTimeString());

    $jobs = Queue::pushed(ProcessTelemetryBatch::class);

    expect($jobs)->not->toBeEmpty()
        ->and($jobs->every(fn (ProcessTelemetryBatch $job): bool => $job->app === 'checkout'))->toBeTrue();
});

it('never caches rejected credentials', function (array|Closure $attributes): void {
    $attributes = $attributes instanceof Closure ? $attributes() : $attributes;
    $minted = $this->mintCredential(array_replace(ingestCacheCredentialAttributes(), $attributes));
    $observed = watchIngestCredentialBoundaries();

    foreach (range(1, 2) as $attempt) {
        $this->actingAsCredential($minted)
            ->postJson('/ingest', ingestCacheEnvelope())
            ->assertUnauthorized();
    }

    $key = 'hone-server:ingest-credential:'.hash('sha256', $minted->plaintext());

    expect($observed->lookups)->toBe(2)
        ->and($observed->usageWrites)->toBe(0)
        ->and($observed->cacheWrites)->toBe([])
        ->and(Cache::has($key))->toBeFalse()
        ->and($minted->credential->refresh()->last_used_at)->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'wrong purpose' => [['purpose' => CredentialPurpose::Mcp]],
    'wrong subject' => [[
        'purpose' => CredentialPurpose::SystemDeployment,
        'subject_type' => SubjectType::Application,
    ]],
    'account ownership' => [['user_id' => 'account-user']],
    'revoked' => [fn (): array => ['revoked_at' => now()]],
    'expired' => [fn (): array => ['expires_at' => now()->subSecond()]],
]);

it('does not cache missing or unknown credentials', function (): void {
    $observed = watchIngestCredentialBoundaries();

    $this->postJson('/ingest', ingestCacheEnvelope())->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.bin2hex(random_bytes(32)))
        ->postJson('/ingest', ingestCacheEnvelope())
        ->assertUnauthorized();

    expect($observed->lookups)->toBe(1)
        ->and($observed->usageWrites)->toBe(0)
        ->and($observed->cacheWrites)->toBe([]);
    Queue::assertNothingPushed();
});

it('does not cache a credential when its guarded usage write fails', function (): void {
    $minted = $this->mintCredential(ingestCacheCredentialAttributes());
    $observed = watchIngestCredentialBoundaries();
    $revokedDuringAuthentication = false;

    DB::listen(function (QueryExecuted $query) use ($minted, &$revokedDuringAuthentication): void {
        if (! $revokedDuringAuthentication
            && str_starts_with(strtolower($query->sql), 'select')
            && str_contains(strtolower($query->sql), '"secret_hash"')) {
            $revokedDuringAuthentication = true;
            Credential::query()
                ->whereKey($minted->credential->id)
                ->update(['revoked_at' => now()]);
        }
    });

    $this->actingAsCredential($minted)
        ->postJson('/ingest', ingestCacheEnvelope())
        ->assertUnauthorized();
    $this->postJson('/ingest', ingestCacheEnvelope())->assertUnauthorized();

    expect($revokedDuringAuthentication)->toBeTrue()
        ->and($observed->lookups)->toBe(2)
        ->and($observed->usageWrites)->toBeGreaterThan(0)
        ->and($observed->cacheWrites)->toBe([])
        ->and($minted->credential->refresh()->last_used_at)->toBeNull();
    Queue::assertNothingPushed();
});

it('does not cache a credential beyond its own expiration', function (): void {
    $minted = $this->mintCredential(array_replace(
        ingestCacheCredentialAttributes(),
        ['expires_at' => now()->addSeconds(5)],
    ));
    $observed = watchIngestCredentialBoundaries();

    $this->actingAsCredential($minted)
        ->postJson('/ingest', ingestCacheEnvelope())
        ->assertAccepted();
    $this->travel(5)->seconds();
    $this->postJson('/ingest', ingestCacheEnvelope())->assertUnauthorized();

    expect($observed->lookups)->toBe(2)
        ->and($observed->usageWrites)->toBe(1)
        ->and($observed->cacheWrites)->toHaveCount(1);
});

it('still validates malformed envelopes on a credential cache hit', function (): void {
    $minted = $this->mintCredential(ingestCacheCredentialAttributes());
    $observed = watchIngestCredentialBoundaries();

    $this->actingAsCredential($minted)
        ->postJson('/ingest', ['nope' => true])
        ->assertUnprocessable();
    $this->postJson('/ingest', ['nope' => true])->assertUnprocessable();

    expect($observed->lookups)->toBe(1)
        ->and($observed->usageWrites)->toBe(1)
        ->and($observed->cacheWrites)->toHaveCount(1);
    Queue::assertNothingPushed();
});

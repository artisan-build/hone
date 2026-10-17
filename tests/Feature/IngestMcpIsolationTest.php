<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\HoneContracts\Envelope;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class, WithCredentials::class);

it('refuses an ingest credential on the real MCP route after a database cache hit', function (): void {
    app()->instance(Repository::class, Cache::store('database'));
    Queue::fake();
    $minted = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'checkout',
    ]);
    $lookups = 0;
    $usageWrites = 0;

    DB::listen(function (QueryExecuted $query) use (&$lookups, &$usageWrites): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select')
            && str_contains($sql, 'from "credentials"')
            && str_contains($sql, '"secret_hash"')) {
            $lookups++;
        }

        if (str_starts_with($sql, 'update "credentials" set "last_used_at"')) {
            $usageWrites++;
        }
    });

    $envelope = Envelope::make('forged-app', null, now()->toAtomString(), [])->toArray();
    $this->actingAsCredential($minted)->postJson('/ingest', $envelope)->assertAccepted();
    $this->postJson('/ingest', $envelope)->assertAccepted();

    expect($lookups)->toBe(1)->and($usageWrites)->toBe(1);
    Queue::assertPushed(ProcessTelemetryBatch::class);

    $key = 'hone-server:ingest-credential:'.hash('sha256', (string) $minted->plaintext());
    $cacheRow = DB::table('cache')->get()->first(
        fn (object $row): bool => str_ends_with($row->key, $key),
    );

    expect($cacheRow)->not->toBeNull()
        ->and(str_contains((string) $cacheRow->key, (string) $minted->plaintext()))->toBeFalse()
        ->and(str_contains((string) $cacheRow->value, (string) $minted->plaintext()))->toBeFalse();

    $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'list-apps-tool',
            'arguments' => [],
        ],
    ])->assertUnauthorized();
});

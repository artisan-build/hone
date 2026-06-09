<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Models\Aggregate;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Models\Sample;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the storage tables with jsonb payload columns', function (): void {
    expect(Schema::connection('hone')->hasTable('raw_events'))->toBeTrue()
        ->and(Schema::connection('hone')->hasTable('aggregates'))->toBeTrue()
        ->and(Schema::connection('hone')->hasTable('samples'))->toBeTrue();

    $payloadColumns = DB::connection('hone')->table('information_schema.columns')
        ->where('table_schema', 'public')
        ->whereIn('table_name', ['raw_events', 'samples'])
        ->where('column_name', 'payload')
        ->pluck('data_type', 'table_name')
        ->all();

    expect($payloadColumns)->toBe([
        'raw_events' => 'jsonb',
        'samples' => 'jsonb',
    ]);
});

it('round trips raw event payloads through jsonb without interpreting them', function (): void {
    $payload = ['t' => 'query', 'nested' => ['a' => 1], 'list' => [1, 2, 3]];

    $rawEvent = RawEvent::factory()->create(['payload' => $payload]);

    expect($rawEvent->fresh()->payload)->toEqual($payload);
});

it('keeps aggregate rollups idempotent for real and unknown deploys', function (?string $deploy): void {
    $attributes = [
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => $deploy,
        'bucket_date' => Carbon::parse('2026-06-09'),
        'metric' => 'duration_p95_ms',
        'value' => 42.5,
        'sample_count' => 10,
    ];

    Aggregate::factory()->create($attributes);

    DB::connection('hone')->table('aggregates')->upsert(
        values: [array_merge($attributes, [
            'value' => 84.0,
            'sample_count' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ])],
        uniqueBy: ['app', 'record_type', 'normalized_key', 'deploy', 'bucket_date', 'metric'],
        update: ['value', 'sample_count', 'updated_at'],
    );

    expect(Aggregate::query()
        ->where('app', 'checkout')
        ->where('record_type', 'query')
        ->where('normalized_key', 'select-users-by-id')
        ->where('deploy', $deploy)
        ->whereDate('bucket_date', '2026-06-09')
        ->where('metric', 'duration_p95_ms')
        ->count())->toBe(1)
        ->and(Aggregate::query()->sole()->value)->toBe(84.0);
})->with([
    'real deploy' => 'abc123',
    'unknown deploy' => null,
]);

it('creates aggregate and sample factory rows while preserving nullable deploys', function (): void {
    $aggregate = Aggregate::factory()->create(['deploy' => null]);
    $sample = Sample::factory()->create(['deploy' => null]);

    expect($aggregate->exists)->toBeTrue()
        ->and($aggregate->deploy)->toBeNull()
        ->and($sample->exists)->toBeTrue()
        ->and($sample->deploy)->toBeNull()
        ->and($sample->payload)->toHaveKey('t');
});

it('supports representative aggregate read queries', function (): void {
    Aggregate::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'metric' => 'duration_p95_ms',
        'bucket_date' => '2026-06-08',
        'normalized_key' => 'slow-query',
        'value' => 300.0,
    ]);
    Aggregate::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'metric' => 'duration_p95_ms',
        'bucket_date' => '2026-06-09',
        'normalized_key' => 'fast-query',
        'value' => 25.0,
    ]);
    Aggregate::factory()->create([
        'app' => 'billing',
        'record_type' => 'query',
        'metric' => 'duration_p95_ms',
        'bucket_date' => '2026-06-09',
        'normalized_key' => 'other-app-query',
        'value' => 900.0,
    ]);

    $rows = Aggregate::query()
        ->where('app', 'checkout')
        ->where('record_type', 'query')
        ->where('metric', 'duration_p95_ms')
        ->whereBetween('bucket_date', ['2026-06-08', '2026-06-09'])
        ->orderByDesc('value')
        ->pluck('normalized_key')
        ->all();

    expect($rows)->toBe(['slow-query', 'fast-query']);
});

it('creates documented indexes for read and rollup patterns', function (): void {
    $indexes = DB::connection('hone')->table('pg_indexes')
        ->where('schemaname', 'public')
        ->whereIn('tablename', ['raw_events', 'aggregates', 'samples'])
        ->pluck('indexdef', 'indexname')
        ->all();

    expect($indexes)->toHaveKeys([
        'raw_events_app_record_type_occurred_at_index',
        'raw_events_app_record_type_normalized_key_index',
        'aggregates_rollup_unique',
        'aggregates_app_record_type_metric_bucket_date_index',
        'samples_app_record_type_normalized_key_occurred_at_index',
    ])->and($indexes['aggregates_rollup_unique'])->toContain('NULLS NOT DISTINCT');
});

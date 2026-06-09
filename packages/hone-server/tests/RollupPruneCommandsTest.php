<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Models\Aggregate;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Models\Sample;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    DB::connection('hone')->statement("SET TIME ZONE 'UTC'");
    Carbon::setTestNow();
});

it('rolls raw events into count and duration aggregate metrics', function (): void {
    $occurredAt = Carbon::parse('2026-06-09 12:00:00+00');

    foreach ([10, 20, 30, 40, 100] as $duration) {
        RawEvent::factory()->create([
            'app' => 'checkout',
            'record_type' => 'query',
            'normalized_key' => 'select-users-by-id',
            'deploy' => 'abc123',
            'occurred_at' => $occurredAt,
            'payload' => ['duration_ms' => $duration],
        ]);
    }

    Artisan::call('hone:rollup');

    $aggregates = Aggregate::query()
        ->where('app', 'checkout')
        ->where('record_type', 'query')
        ->where('normalized_key', 'select-users-by-id')
        ->where('deploy', 'abc123')
        ->whereDate('bucket_date', '2026-06-09')
        ->pluck('value', 'metric');

    $expectedP95 = percentileFor([10, 20, 30, 40, 100], 0.95);
    $expectedP99 = percentileFor([10, 20, 30, 40, 100], 0.99);

    expect($aggregates)->toHaveCount(5)
        ->and($aggregates['count'])->toBe(5.0)
        ->and($aggregates['avg'])->toBe(40.0)
        ->and($aggregates['max'])->toBe(100.0)
        ->and(abs($aggregates['p95'] - $expectedP95))->toBeLessThan(0.5)
        ->and(abs($aggregates['p99'] - $expectedP99))->toBeLessThan(0.5)
        ->and(Aggregate::query()->pluck('sample_count')->unique()->all())->toBe([5]);
});

it('keeps rollups idempotent for unknown deploy groups', function (): void {
    RawEvent::factory()->count(2)->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
        'payload' => ['duration' => 25],
    ]);

    Artisan::call('hone:rollup');

    $firstRunCount = Aggregate::query()->count();
    $firstRunValues = Aggregate::query()->orderBy('metric')->pluck('value', 'metric')->all();

    Artisan::call('hone:rollup');

    expect(Aggregate::query()->count())->toBe($firstRunCount)
        ->and(Aggregate::query()->orderBy('metric')->pluck('value', 'metric')->all())->toBe($firstRunValues);
});

it('ignores non-numeric duration payloads without aborting the rollup', function (): void {
    foreach ([['duration' => 'fast'], ['duration_ms' => 50]] as $payload) {
        RawEvent::factory()->create([
            'app' => 'checkout',
            'record_type' => 'query',
            'normalized_key' => 'select-users-by-id',
            'deploy' => 'abc123',
            'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
            'payload' => $payload,
        ]);
    }

    Artisan::call('hone:rollup');

    $aggregates = Aggregate::query()->pluck('value', 'metric');

    expect($aggregates)->toHaveCount(5)
        ->and($aggregates['count'])->toBe(2.0)
        ->and($aggregates['avg'])->toBe(50.0)
        ->and($aggregates['max'])->toBe(50.0)
        ->and($aggregates['p95'])->toBe(50.0)
        ->and($aggregates['p99'])->toBe(50.0)
        ->and(Aggregate::query()->pluck('sample_count')->unique()->all())->toBe([2]);
});

it('does not overwrite complete aggregates with lower sample partial rerollups', function (): void {
    $eventAttributes = [
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => 'abc123',
        'occurred_at' => Carbon::parse('2026-06-09 12:00:00+00'),
    ];

    foreach ([10, 20, 30, 40, 50] as $duration) {
        RawEvent::factory()->create($eventAttributes + ['payload' => ['duration_ms' => $duration]]);
    }

    Artisan::call('hone:rollup');

    expect(Aggregate::query()->where('metric', 'count')->sole()->value)->toBe(5.0);

    $deletedIds = RawEvent::query()->orderBy('id')->limit(2)->pluck('id');
    RawEvent::query()->whereIn('id', $deletedIds)->delete();

    Artisan::call('hone:rollup');

    expect(Aggregate::query()->where('metric', 'count')->sole()->value)->toBe(5.0)
        ->and(Aggregate::query()->where('metric', 'count')->sole()->sample_count)->toBe(5);

    foreach ([60, 70, 80] as $duration) {
        RawEvent::factory()->create($eventAttributes + ['payload' => ['duration_ms' => $duration]]);
    }

    Artisan::call('hone:rollup');

    expect(Aggregate::query()->where('metric', 'count')->sole()->value)->toBe(6.0)
        ->and(Aggregate::query()->where('metric', 'count')->sole()->sample_count)->toBe(6);
});

it('separates raw events into calendar day buckets', function (): void {
    DB::connection('hone')->statement("SET TIME ZONE 'Asia/Tokyo'");

    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => 'abc123',
        'occurred_at' => Carbon::parse('2026-06-08 23:30:00+00'),
        'payload' => ['duration_ms' => 10],
    ]);
    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => 'abc123',
        'occurred_at' => Carbon::parse('2026-06-09 12:01:00+00'),
        'payload' => ['duration_ms' => 20],
    ]);

    Artisan::call('hone:rollup');

    expect(Aggregate::query()
        ->where('metric', 'count')
        ->orderBy('bucket_date')
        ->pluck('bucket_date')
        ->map(fn (Carbon $bucketDate): string => $bucketDate->toDateString())
        ->all())->toBe(['2026-06-08', '2026-06-09']);
});

it('prunes expired raw events samples and aggregates while keeping in-window rows', function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
    config()->set('hone-server.retention', [
        'raw_hours' => 2,
        'sample_days' => 3,
        'aggregate_days' => 10,
    ]);

    $expiredRaw = RawEvent::factory()->create(['occurred_at' => now()->subHours(3)]);
    $keptRaw = RawEvent::factory()->create(['occurred_at' => now()->subHour()]);
    $expiredSample = Sample::factory()->create(['occurred_at' => now()->subDays(4)]);
    $keptSample = Sample::factory()->create(['occurred_at' => now()->subDays(2)]);
    $expiredAggregate = Aggregate::factory()->create(['bucket_date' => now()->subDays(11)->toDateString()]);
    $keptAggregate = Aggregate::factory()->create(['bucket_date' => now()->subDays(10)->toDateString()]);

    Artisan::call('hone:prune');

    expect(RawEvent::query()->whereKey($expiredRaw->getKey())->exists())->toBeFalse()
        ->and(RawEvent::query()->whereKey($keptRaw->getKey())->exists())->toBeTrue()
        ->and(Sample::query()->whereKey($expiredSample->getKey())->exists())->toBeFalse()
        ->and(Sample::query()->whereKey($keptSample->getKey())->exists())->toBeTrue()
        ->and(Aggregate::query()->whereKey($expiredAggregate->getKey())->exists())->toBeFalse()
        ->and(Aggregate::query()->whereKey($keptAggregate->getKey())->exists())->toBeTrue();
});

it('keeps aggregates created before pruning expired raw events', function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
    config()->set('hone-server.retention.raw_hours', 1);

    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => 'abc123',
        'occurred_at' => now(),
        'payload' => ['duration_ms' => 10],
    ]);

    Artisan::call('hone:rollup');

    Carbon::setTestNow('2026-06-09 14:00:00+00');

    Artisan::call('hone:prune');

    expect(RawEvent::query()->count())->toBe(0)
        ->and(Aggregate::query()->where('metric', 'count')->count())->toBe(1);
});

it('registers both commands and schedules rollup before prune', function (): void {
    expect(Artisan::all())->toHaveKeys(['hone:rollup', 'hone:prune']);

    $commands = collect(app(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->values();

    $rollupIndex = $commands->search(fn (string $command): bool => str_contains($command, 'hone:rollup'));
    $pruneIndex = $commands->search(fn (string $command): bool => str_contains($command, 'hone:prune'));

    expect($rollupIndex)->not->toBeFalse()
        ->and($pruneIndex)->not->toBeFalse()
        ->and($rollupIndex)->toBeLessThan($pruneIndex);
});

/**
 * @param  list<int>  $values
 */
function percentileFor(array $values, float $percentile): float
{
    $row = DB::connection('hone')->selectOne(
        'select percentile_cont(?) within group (order by value) as percentile from unnest(?::double precision[]) as value',
        [$percentile, '{'.implode(',', $values).'}'],
    );

    return (float) $row->percentile;
}

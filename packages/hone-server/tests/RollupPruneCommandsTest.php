<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Maintenance\MaintenanceHealth;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Models\Aggregate;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Models\Sample;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // The rollup reads a trailing window ending now, so pin the clock to the fixture day.
    Carbon::setTestNow('2026-06-09 13:00:00+00');
});

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

it('converts Nightwatch microsecond durations to milliseconds', function (): void {
    $occurredAt = Carbon::parse('2026-06-09 12:00:00+00');

    // Nightwatch emits `duration` in microseconds; these are 10/20/30/40/100 ms.
    foreach ([10_000, 20_000, 30_000, 40_000, 100_000] as $durationMicros) {
        RawEvent::factory()->create([
            'app' => 'checkout',
            'record_type' => 'query',
            'normalized_key' => 'delete-from-sessions',
            'deploy' => 'abc123',
            'occurred_at' => $occurredAt,
            'payload' => ['duration' => $durationMicros],
        ]);
    }

    Artisan::call('hone:rollup');

    $aggregates = Aggregate::query()
        ->where('app', 'checkout')
        ->where('record_type', 'query')
        ->where('normalized_key', 'delete-from-sessions')
        ->whereDate('bucket_date', '2026-06-09')
        ->pluck('value', 'metric');

    $expectedP95 = percentileFor([10, 20, 30, 40, 100], 0.95);

    expect($aggregates['avg'])->toBe(40.0)
        ->and($aggregates['max'])->toBe(100.0)
        ->and(abs($aggregates['p95'] - $expectedP95))->toBeLessThan(0.5);
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
    app(MaintenanceMarkers::class)->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::now());

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
        'occurred_at' => now()->subMinute(),
        'payload' => ['duration_ms' => 10],
    ]);

    Artisan::call('hone:rollup');

    Carbon::setTestNow('2026-06-09 14:00:00+00');

    Artisan::call('hone:prune');

    expect(RawEvent::query()->count())->toBe(0)
        ->and(Aggregate::query()->where('metric', 'count')->count())->toBe(1);
});

it('runs maintenance by rolling up before pruning expired raw events', function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
    config()->set('hone-server.retention.raw_hours', 1);

    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'deploy' => 'abc123',
        'occurred_at' => now()->subHours(2),
        'payload' => ['duration_ms' => 10],
    ]);

    Artisan::call('hone:maintain');

    expect(Aggregate::query()->where('metric', 'count')->count())->toBe(1)
        ->and(RawEvent::query()->count())->toBe(0);
});

it('registers rollup prune and maintain commands while scheduling only maintain', function (): void {
    expect(Artisan::all())->toHaveKeys(['hone:maintain', 'hone:rollup', 'hone:prune']);

    $commands = collect(app(Schedule::class)->events())
        ->pluck('command')
        ->filter()
        ->values();

    $maintainCommands = $commands->filter(fn (string $command): bool => str_contains($command, 'hone:maintain'));

    expect($maintainCommands)->toHaveCount(1)
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'hone:rollup')))->toBeFalse()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'hone:prune')))->toBeFalse();
});

it('writes more than 6,553 aggregate rows in one rollup run', function (): void {
    // One count-only group per key: 6,600 aggregate rows is 66,000 bindings if written as one statement.
    seedDistinctRawEventGroups(6600, now()->subMinutes(30));

    $exitCode = Artisan::call('hone:rollup');

    expect($exitCode)->toBe(0)
        ->and(Aggregate::query()->count())->toBe(6600)
        ->and(Aggregate::query()->where('metric', 'count')->where('value', 1)->count())->toBe(6600);
});

it('never issues a rollup statement carrying more than 65,535 bindings', function (): void {
    seedDistinctRawEventGroups(6600, now()->subMinutes(30));

    $bindingCounts = [];
    DB::connection('hone')->listen(function (QueryExecuted $query) use (&$bindingCounts): void {
        $bindingCounts[] = count($query->bindings);
    });

    Artisan::call('hone:rollup');

    expect($bindingCounts)->not->toBeEmpty()
        ->and(max($bindingCounts))->toBeLessThanOrEqual(65535)
        ->and(Aggregate::query()->count())->toBe(6600);
});

it('reads only the trailing window and leaves out-of-window aggregates untouched', function (): void {
    $staleUpdatedAt = Carbon::parse('2026-06-05 23:00:00+00');
    $outOfWindowAggregate = Aggregate::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'old-query',
        'deploy' => null,
        'bucket_date' => '2026-06-05',
        'metric' => 'count',
        'value' => 1,
        'sample_count' => 1,
        'created_at' => $staleUpdatedAt,
        'updated_at' => $staleUpdatedAt,
    ]);

    RawEvent::factory()->count(5)->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'old-query',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-05 12:00:00+00'),
        'payload' => ['sql' => 'select 1'],
    ]);
    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'old-unaggregated-query',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-06 12:00:00+00'),
        'payload' => ['sql' => 'select 2'],
    ]);
    RawEvent::factory()->create([
        'app' => 'checkout',
        'record_type' => 'query',
        'normalized_key' => 'new-query',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-08 01:00:00+00'),
        'payload' => ['sql' => 'select 3'],
    ]);

    DB::connection('hone')->enableQueryLog();
    Artisan::call('hone:rollup');
    $rawEventStatements = collect(DB::connection('hone')->getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'from raw_events'));
    DB::connection('hone')->disableQueryLog();

    $untouched = Aggregate::query()->findOrFail($outOfWindowAggregate->getKey());

    expect($untouched->value)->toBe(1.0)
        ->and($untouched->sample_count)->toBe(1)
        ->and($untouched->updated_at->equalTo($staleUpdatedAt))->toBeTrue()
        ->and(Aggregate::query()->where('normalized_key', 'old-unaggregated-query')->exists())->toBeFalse()
        ->and(Aggregate::query()->where('normalized_key', 'new-query')->where('metric', 'count')->value('value'))->toBe(1.0)
        ->and($rawEventStatements)->not->toBeEmpty()
        ->and($rawEventStatements->every(fn (string $sql): bool => str_contains($sql, 'occurred_at >= ?') || str_contains($sql, '"occurred_at" <')))->toBeTrue();
});

it('widens the rollup read window through the late arrival setting', function (): void {
    config()->set('hone-server.rollup.late_arrival_hours', 72);

    RawEvent::factory()->create([
        'app' => 'checkout',
        'normalized_key' => 'three-days-ago',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-06 00:00:00+00'),
    ]);
    RawEvent::factory()->create([
        'app' => 'checkout',
        'normalized_key' => 'four-days-ago',
        'deploy' => null,
        'occurred_at' => Carbon::parse('2026-06-05 23:59:59+00'),
    ]);

    Artisan::call('hone:rollup');

    expect(Aggregate::query()->where('metric', 'count')->pluck('normalized_key')->all())->toBe(['three-days-ago']);
});

it('registers maintenance so a second invocation cannot start while the first holds the overlap lock', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'hone:maintain'));

    expect($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(120)
        ->and($event->filtersPass(app()))->toBeTrue();

    expect($event->mutex->create($event))->toBeTrue();

    expect($event->filtersPass(app()))->toBeFalse();

    $event->mutex->forget($event);

    expect($event->filtersPass(app()))->toBeTrue();
});

it('backfills an explicit range one bucket day at a time without reading outside it', function (): void {
    foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $day) {
        RawEvent::factory()->create([
            'app' => 'checkout',
            'normalized_key' => 'key-'.$day,
            'deploy' => null,
            'occurred_at' => Carbon::parse($day.' 12:00:00+00'),
        ]);
    }

    DB::connection('hone')->enableQueryLog();
    $exitCode = Artisan::call('hone:backfill', ['from' => '2026-06-02', 'to' => '2026-06-04']);
    $groupingReads = collect(DB::connection('hone')->getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'GROUP BY'));
    DB::connection('hone')->disableQueryLog();

    expect($exitCode)->toBe(0)
        ->and($groupingReads)->toHaveCount(3)
        ->and($groupingReads->every(fn (array $entry): bool => str_contains($entry['query'], 'WHERE occurred_at >= ? AND occurred_at < ?')))->toBeTrue()
        ->and(Aggregate::query()->where('metric', 'count')->orderBy('normalized_key')->pluck('normalized_key')->all())
        ->toBe(['key-2026-06-02', 'key-2026-06-03', 'key-2026-06-04'])
        ->and(app(MaintenanceMarkers::class)->get('backfill.2026-06-02.2026-06-04'))->toBe('2026-06-04');
});

it('resumes an interrupted backfill from its checkpoint', function (): void {
    foreach (['2026-06-02', '2026-06-03', '2026-06-04'] as $day) {
        RawEvent::factory()->create([
            'app' => 'checkout',
            'normalized_key' => 'key-'.$day,
            'deploy' => null,
            'occurred_at' => Carbon::parse($day.' 12:00:00+00'),
        ]);
    }

    // A run that completed 2026-06-02 and was then interrupted.
    app(MaintenanceMarkers::class)->put('backfill.2026-06-02.2026-06-04', '2026-06-02');

    Artisan::call('hone:backfill', ['from' => '2026-06-02', 'to' => '2026-06-04']);

    expect(Aggregate::query()->where('metric', 'count')->orderBy('normalized_key')->pluck('normalized_key')->all())
        ->toBe(['key-2026-06-03', 'key-2026-06-04']);

    DB::connection('hone')->enableQueryLog();
    Artisan::call('hone:backfill', ['from' => '2026-06-02', 'to' => '2026-06-04']);
    $readsOnCompletedRerun = collect(DB::connection('hone')->getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'GROUP BY'));
    DB::connection('hone')->disableQueryLog();

    expect($readsOnCompletedRerun)->toBeEmpty()
        ->and(Artisan::output())->toContain('already complete');

    Artisan::call('hone:backfill', ['from' => '2026-06-02', 'to' => '2026-06-04', '--restart' => true]);

    expect(Aggregate::query()->where('metric', 'count')->count())->toBe(3);
});

it('rejects backfill ranges that are malformed, reversed, or end after today', function (array $arguments): void {
    expect(Artisan::call('hone:backfill', $arguments))->toBe(2)
        ->and(app(MaintenanceMarkers::class)->get('backfill.'.$arguments['from'].'.'.$arguments['to']))->toBeNull();
})->with([
    'reversed' => [['from' => '2026-06-04', 'to' => '2026-06-02']],
    'future' => [['from' => '2026-06-08', 'to' => '2026-06-10']],
    'malformed' => [['from' => '2026-6-1', 'to' => '2026-06-02']],
    'impossible date' => [['from' => '2026-02-30', 'to' => '2026-03-02']],
]);

it('does not advance the watermark across a gap until a backfill closes it', function (): void {
    $markers = app(MaintenanceMarkers::class);
    $markers->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::parse('2026-06-03 10:00:00+00'));

    RawEvent::factory()->create(['deploy' => null, 'occurred_at' => Carbon::parse('2026-06-05 12:00:00+00')]);

    // The hourly window starts 2026-06-08, leaving 2026-06-03 10:00 onwards unaggregated.
    Artisan::call('hone:rollup');

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-03T10:00:00Z')
        ->and(Artisan::output())->toContain('watermark not advanced');

    Artisan::call('hone:backfill', ['from' => '2026-06-03', 'to' => '2026-06-07']);

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-08T00:00:00Z');

    Artisan::call('hone:rollup');

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-09T13:00:00Z');
});

it('starts the watermark on a fresh install only when nothing older than the window exists', function (): void {
    $markers = app(MaintenanceMarkers::class);

    RawEvent::factory()->create(['deploy' => null, 'occurred_at' => Carbon::parse('2026-06-01 12:00:00+00')]);

    Artisan::call('hone:rollup');

    expect($markers->rollupWatermark())->toBeNull();

    RawEvent::query()->delete();

    Artisan::call('hone:rollup');

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-09T13:00:00Z');
});

it('seeds the watermark from the last legacy whole-table rollup write', function (): void {
    $markers = app(MaintenanceMarkers::class);

    $markers->seedRollupWatermarkFromLegacyRollup();

    expect($markers->rollupWatermark())->toBeNull();

    Aggregate::factory()->create(['updated_at' => Carbon::parse('2026-06-07 01:01:33+00')]);
    Aggregate::factory()->create(['updated_at' => Carbon::parse('2026-06-06 01:01:33+00')]);

    $markers->seedRollupWatermarkFromLegacyRollup();

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-07T01:01:33Z');

    Aggregate::factory()->create(['updated_at' => Carbon::parse('2026-06-08 01:01:33+00')]);
    $markers->seedRollupWatermarkFromLegacyRollup();

    expect($markers->rollupWatermark()?->toIso8601ZuluString())->toBe('2026-06-07T01:01:33Z');
});

it('retains never-aggregated raw events and prunes aggregated ones when the rollup fails', function (): void {
    config()->set('hone-server.retention.raw_hours', 2);
    app(MaintenanceMarkers::class)->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::parse('2026-06-09 09:00:00+00'));

    $aggregatedAndExpired = RawEvent::factory()->create(['occurred_at' => Carbon::parse('2026-06-09 08:00:00+00')]);
    $unaggregatedAndExpired = RawEvent::factory()->create(['occurred_at' => Carbon::parse('2026-06-09 10:00:00+00')]);
    $insideRetention = RawEvent::factory()->create(['occurred_at' => Carbon::parse('2026-06-09 12:30:00+00')]);

    Artisan::command('hone:rollup', function (): never {
        throw new RuntimeException('rollup exploded');
    });

    $exitCode = Artisan::call('hone:maintain');

    expect($exitCode)->toBe(1)
        ->and(RawEvent::query()->whereKey($aggregatedAndExpired->getKey())->exists())->toBeFalse()
        ->and(RawEvent::query()->whereKey($unaggregatedAndExpired->getKey())->exists())->toBeTrue()
        ->and(RawEvent::query()->whereKey($insideRetention->getKey())->exists())->toBeTrue();
});

it('prunes nothing raw before any rollup watermark exists', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);

    RawEvent::factory()->create(['occurred_at' => now()->subDays(5)]);

    Artisan::call('hone:prune');

    expect(RawEvent::query()->count())->toBe(1)
        ->and(Artisan::output())->toContain('not yet aggregated');
});

it('reports a thrown rollup with its exception class, still prunes, and exits non-zero', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);
    app(MaintenanceMarkers::class)->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::now());
    $expired = RawEvent::factory()->create(['occurred_at' => now()->subHours(3)]);

    Artisan::command('hone:rollup', function (): never {
        throw new LogicException('rollup exploded');
    });

    $this->artisan('hone:maintain')
        ->expectsOutputToContain('hone:rollup threw LogicException: rollup exploded')
        ->assertExitCode(1);

    $markers = app(MaintenanceMarkers::class);

    expect(RawEvent::query()->whereKey($expired->getKey())->exists())->toBeFalse()
        ->and($markers->get(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS))->toBeNull()
        ->and($markers->get(MaintenanceMarkers::MAINTAIN_LAST_FAILURE_REASON))->toContain('LogicException')
        ->and($markers->timestamp(MaintenanceMarkers::MAINTAIN_LAST_FAILURE)?->toIso8601ZuluString())->toBe('2026-06-09T13:00:00Z');
});

it('reports a rollup that returns a failure code the same way', function (): void {
    Artisan::command('hone:rollup', fn (): int => 3);

    $this->artisan('hone:maintain')
        ->expectsOutputToContain('hone:rollup exited with code 3.')
        ->assertExitCode(1);

    expect(app(MaintenanceMarkers::class)->get(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS))->toBeNull();
});

it('reports a thrown prune after a successful rollup', function (): void {
    Artisan::command('hone:prune', function (): never {
        throw new RuntimeException('prune exploded');
    });

    $this->artisan('hone:maintain')
        ->expectsOutputToContain('hone:prune threw RuntimeException: prune exploded')
        ->assertExitCode(1);
});

it('records a durable last successful maintenance completion', function (): void {
    expect(Artisan::call('hone:maintain'))->toBe(0);

    Carbon::setTestNow('2026-06-09 13:45:00+00');

    $report = app(MaintenanceHealth::class)->report();

    expect(app(MaintenanceMarkers::class)->timestamp(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS)?->toIso8601ZuluString())->toBe('2026-06-09T13:00:00Z')
        ->and($report['checks']['maintenance']['last_success_at'])->toBe('2026-06-09T13:00:00Z')
        ->and($report['checks']['maintenance']['age_minutes'])->toBe(45);
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

function seedDistinctRawEventGroups(int $groups, DateTimeInterface $occurredAt): void
{
    DB::connection('hone')->statement(
        "insert into raw_events (app, record_type, deploy, occurred_at, normalized_key, payload)
         select 'checkout', 'query', null, ?, 'key-' || n, '{}'::jsonb from generate_series(1, ?) as n",
        [$occurredAt, $groups],
    );
}

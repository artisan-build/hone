<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Models\ActivityBucket;
use ArtisanBuild\HoneServer\Models\Aggregate;
use ArtisanBuild\HoneServer\Models\BackgroundActivityBucket;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-09 13:00:00+00');
});

afterEach(function (): void {
    DB::connection('hone')->statement("SET TIME ZONE 'UTC'");
    Carbon::setTestNow();
});

it('rolls execution activity into isolated UTC minute buckets idempotently', function (): void {
    foreach ([
        ['human', false],
        ['guest', false],
        ['guest', true],
        ['scheduled', false],
        ['scheduled', true],
        ['scheduled', null],
        ['job', false],
        ['job', true],
        ['job', null],
        ['command', true],
    ] as [$actor, $ranQueries]) {
        rawActivity('checkout', '2026-06-09 12:34:30+00', $actor, $ranQueries);
    }

    rawActivity('billing', '2026-06-09 12:34:45+00', 'guest', true);

    DB::connection('hone')->statement("SET TIME ZONE 'Asia/Tokyo'");
    Artisan::call('hone:rollup');
    Artisan::call('hone:rollup');
    DB::connection('hone')->statement("SET TIME ZONE 'UTC'");

    $checkout = ActivityBucket::query()->where('app', 'checkout')->sole();
    $billing = ActivityBucket::query()->where('app', 'billing')->sole();

    expect(ActivityBucket::query()->count())->toBe(2)
        ->and($checkout->bucket_minute->utc()->toIso8601ZuluString())->toBe('2026-06-09T12:34:00Z')
        ->and($checkout->human_requests)->toBe(1)
        ->and($checkout->guest_requests)->toBe(2)
        ->and($checkout->guest_requests_with_queries)->toBe(1)
        ->and($checkout->scheduled_runs_with_queries)->toBe(1)
        ->and($checkout->scheduled_runs_without_queries)->toBe(1)
        ->and($checkout->jobs_with_queries)->toBe(1)
        ->and($checkout->jobs_without_queries)->toBe(1)
        ->and($billing->guest_requests)->toBe(1)
        ->and($billing->guest_requests_with_queries)->toBe(1);

    RawEvent::query()->where('app', 'checkout')->delete();
    rawActivity('checkout', '2026-06-09 12:34:59+00', 'guest', true);
    Artisan::call('hone:rollup');

    expect($checkout->fresh()->guest_requests)->toBe(3)
        ->and($checkout->fresh()->guest_requests_with_queries)->toBe(2)
        ->and(ActivityBucket::query()->count())->toBe(2);
});

it('places activity one second either side of an exact five minute boundary', function (): void {
    Carbon::setTestNow('2026-06-09 09:10:00+00');

    rawActivity('checkout', '2026-06-09 09:04:59+00', 'human', true);
    rawActivity('checkout', '2026-06-09 09:05:00+00', 'human', true);
    rawActivity('checkout', '2026-06-09 09:05:01+00', 'human', true);

    Artisan::call('hone:rollup');

    expect(ActivityBucket::query()
        ->where('app', 'checkout')
        ->orderBy('bucket_minute')
        ->pluck('human_requests')
        ->all())->toBe([1, 2])
        ->and(ActivityBucket::query()
            ->where('app', 'checkout')
            ->orderBy('bucket_minute')
            ->get()
            ->map(fn (ActivityBucket $bucket): string => $bucket->bucket_minute->utc()->toIso8601ZuluString())
            ->all())->toBe([
                '2026-06-09T09:04:00Z',
                '2026-06-09T09:05:00Z',
            ]);
});

it('buckets expired raw activity before maintenance prunes it', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);
    app(MaintenanceMarkers::class)->putTimestamp(
        MaintenanceMarkers::ROLLUP_WATERMARK,
        CarbonImmutable::now('UTC'),
    );

    $rawEvent = rawActivity('checkout', '2026-06-09 10:15:20+00', 'guest', true);

    expect(app(MaintenanceMarkers::class)->activityRollupWatermark())->toBeNull();

    Artisan::call('hone:maintain');

    $bucket = ActivityBucket::query()->sole();

    expect(RawEvent::query()->whereKey($rawEvent->getKey())->exists())->toBeFalse()
        ->and($bucket->bucket_minute->utc()->toIso8601ZuluString())->toBe('2026-06-09T10:15:00Z')
        ->and($bucket->guest_requests)->toBe(1)
        ->and($bucket->guest_requests_with_queries)->toBe(1)
        ->and(app(MaintenanceMarkers::class)->activityRollupWatermark()?->toIso8601ZuluString())
        ->toBe('2026-06-09T13:00:00Z');
});

it('claims timeline activity before daily aggregate scans', function (): void {
    rawActivity('checkout', '2026-06-09 12:30:00+00', 'guest', true);

    DB::connection('hone')->enableQueryLog();
    Artisan::call('hone:rollup');
    $queries = collect(DB::connection('hone')->getQueryLog())->pluck('query')->values();
    DB::connection('hone')->disableQueryLog();

    $activityIndex = $queries->search(fn (string $query): bool => str_contains($query, 'UPDATE raw_events')
        && str_contains($query, 'activity_bucketed_at'));
    $aggregateIndex = $queries->search(fn (string $query): bool => str_contains($query, 'WITH raw_values AS'));

    expect($activityIndex)->toBeInt()
        ->and($aggregateIndex)->toBeInt()
        ->and($activityIndex)->toBeLessThan($aggregateIndex);
});

it('retains timeline buckets independently for 400 days at the exact cutoff', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);
    config()->set('hone-server.retention.aggregate_days', 1);

    $expired = ActivityBucket::factory()->create(['bucket_minute' => now()->subDays(400)->subMinute()]);
    $atCutoff = ActivityBucket::factory()->create(['bucket_minute' => now()->subDays(400)]);
    $newer = ActivityBucket::factory()->create(['bucket_minute' => now()->subDays(2)]);
    $expiredBackground = BackgroundActivityBucket::factory()->create(['bucket_minute' => now()->subDays(400)->subMinute()]);
    $backgroundAtCutoff = BackgroundActivityBucket::factory()->create(['bucket_minute' => now()->subDays(400)]);
    $newerBackground = BackgroundActivityBucket::factory()->create(['bucket_minute' => now()->subDays(2)]);
    $expiredAggregate = Aggregate::factory()->create(['bucket_date' => now()->subDays(2)->toDateString()]);

    Artisan::call('hone:prune');

    expect(config('hone-server.retention.timeline_days'))->toBe(400)
        ->and(ActivityBucket::query()->whereKey($expired->getKey())->exists())->toBeFalse()
        ->and(ActivityBucket::query()->whereKey($atCutoff->getKey())->exists())->toBeTrue()
        ->and(ActivityBucket::query()->whereKey($newer->getKey())->exists())->toBeTrue()
        ->and(BackgroundActivityBucket::query()->whereKey($expiredBackground->getKey())->exists())->toBeFalse()
        ->and(BackgroundActivityBucket::query()->whereKey($backgroundAtCutoff->getKey())->exists())->toBeTrue()
        ->and(BackgroundActivityBucket::query()->whereKey($newerBackground->getKey())->exists())->toBeTrue()
        ->and(Aggregate::query()->whereKey($expiredAggregate->getKey())->exists())->toBeFalse();
});

it('requires both durable rollups before pruning raw activity', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);
    $markers = app(MaintenanceMarkers::class);
    $markers->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::now('UTC'));

    $expired = rawActivity('checkout', '2026-06-09 10:00:00+00', 'guest', false);

    Artisan::call('hone:prune');

    expect(RawEvent::query()->whereKey($expired->getKey())->exists())->toBeTrue();

    $markers->putTimestamp(MaintenanceMarkers::ACTIVITY_ROLLUP_WATERMARK, CarbonImmutable::now('UTC'));
    $expired->update(['activity_bucketed_at' => now()]);
    Artisan::call('hone:prune');

    expect(RawEvent::query()->whereKey($expired->getKey())->exists())->toBeFalse();
});

it('retains raw events that have not committed their activity rollup', function (): void {
    config()->set('hone-server.retention.raw_hours', 1);
    $markers = app(MaintenanceMarkers::class);
    $markers->putTimestamp(MaintenanceMarkers::ROLLUP_WATERMARK, CarbonImmutable::now('UTC'));
    $markers->putTimestamp(MaintenanceMarkers::ACTIVITY_ROLLUP_WATERMARK, CarbonImmutable::now('UTC'));

    $covered = rawActivity('checkout', '2026-06-09 10:00:00+00', 'guest', false);
    $covered->update(['activity_bucketed_at' => now()]);
    $concurrent = rawActivity('checkout', '2026-06-09 10:01:00+00', 'guest', false);

    Artisan::call('hone:prune');

    expect(RawEvent::query()->whereKey($covered->getKey())->exists())->toBeFalse()
        ->and(RawEvent::query()->whereKey($concurrent->getKey())->exists())->toBeTrue();
});

function rawActivity(string $app, string $occurredAt, string $actor, ?bool $ranQueries): RawEvent
{
    return RawEvent::factory()->create([
        'app' => $app,
        'record_type' => match ($actor) {
            'human', 'guest' => 'request',
            'scheduled' => 'scheduled-task',
            'job' => 'job-attempt',
            default => 'command',
        },
        'occurred_at' => Carbon::parse($occurredAt),
        'actor' => $actor,
        'ran_queries' => $ranQueries,
    ]);
}

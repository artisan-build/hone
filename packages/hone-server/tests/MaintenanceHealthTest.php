<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Maintenance\MaintenanceHealth;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Models\Aggregate;
use ArtisanBuild\HoneServer\Models\RawEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
    config()->set('hone-server.retention.raw_hours', 72);
    config()->set('hone-server.health', [
        'ingest_active_minutes' => 60,
        'maintenance_max_age_minutes' => 150,
        'retention_grace_hours' => 24,
        'aggregate_max_age_hours' => 6,
    ]);
});

function activeIngest(): void
{
    RawEvent::factory()->create(['occurred_at' => now()->subMinutes(5)]);
}

it('reads healthy with ingest idle and no data at all', function (): void {
    $report = app(MaintenanceHealth::class)->report();

    expect($report['healthy'])->toBeTrue()
        ->and($report['ingest_active'])->toBeFalse()
        ->and($report['checks']['maintenance']['status'])->toBe(MaintenanceHealth::OK)
        ->and($report['checks']['retention']['status'])->toBe(MaintenanceHealth::IDLE)
        ->and($report['checks']['aggregate_freshness']['status'])->toBe(MaintenanceHealth::IDLE);

    $this->artisan('hone:health')->assertExitCode(0);
});

it('does not alarm retention or freshness while ingest is idle even when both are far past budget', function (): void {
    RawEvent::factory()->create(['occurred_at' => now()->subDays(20)]);
    Aggregate::factory()->create(['bucket_date' => now()->subDays(17)->toDateString()]);

    $report = app(MaintenanceHealth::class)->report();

    expect($report['ingest_active'])->toBeFalse()
        ->and($report['checks']['retention']['status'])->toBe(MaintenanceHealth::IDLE)
        ->and($report['checks']['aggregate_freshness']['status'])->toBe(MaintenanceHealth::IDLE);
});

it('alarms on maintenance recency only after its missed-run budget', function (int $minutesAgo, string $status): void {
    app(MaintenanceMarkers::class)->putTimestamp(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS, CarbonImmutable::now()->subMinutes($minutesAgo));

    $check = app(MaintenanceHealth::class)->report()['checks']['maintenance'];

    expect($check['status'])->toBe($status)
        ->and($check['age_minutes'])->toBe($minutesAgo)
        ->and($check['budget_minutes'])->toBe(150);
})->with([
    'within budget' => [150, MaintenanceHealth::OK],
    'past budget' => [151, MaintenanceHealth::ALARM],
]);

it('alarms when maintenance never succeeded only once active data has waited past the budget', function (int $oldestMinutesAgo, string $status): void {
    activeIngest();
    RawEvent::factory()->create(['occurred_at' => now()->subMinutes($oldestMinutesAgo)]);

    expect(app(MaintenanceHealth::class)->report()['checks']['maintenance']['status'])->toBe($status);
})->with([
    'within budget' => [150, MaintenanceHealth::OK],
    'past budget' => [151, MaintenanceHealth::ALARM],
]);

it('alarms on retention age only after retention plus grace while ingest is active', function (int $oldestMinutesAgo, string $status): void {
    activeIngest();
    RawEvent::factory()->create(['occurred_at' => now()->subMinutes($oldestMinutesAgo)]);

    $check = app(MaintenanceHealth::class)->report()['checks']['retention'];

    expect($check['status'])->toBe($status)
        ->and($check['budget_hours'])->toBe(96);
})->with([
    'within budget' => [96 * 60, MaintenanceHealth::OK],
    'past budget' => [96 * 60 + 1, MaintenanceHealth::ALARM],
]);

it('alarms on aggregate freshness only after its budget while ingest is active', function (string $now, string $status, int $ageHours): void {
    Carbon::setTestNow($now);
    activeIngest();
    Aggregate::factory()->create(['bucket_date' => '2026-06-08']);

    $check = app(MaintenanceHealth::class)->report()['checks']['aggregate_freshness'];

    expect($check['status'])->toBe($status)
        ->and($check['newest_bucket_date'])->toBe('2026-06-08')
        ->and($check['age_hours'])->toBe($ageHours);
})->with([
    'current day bucket still missing within budget' => ['2026-06-09 06:00:00+00', MaintenanceHealth::OK, 6],
    'past budget' => ['2026-06-09 06:01:00+00', MaintenanceHealth::ALARM, 6],
]);

it('reads a current partial bucket as zero aggregate age', function (): void {
    activeIngest();
    Aggregate::factory()->create(['bucket_date' => '2026-06-09']);

    $check = app(MaintenanceHealth::class)->report()['checks']['aggregate_freshness'];

    expect($check['status'])->toBe(MaintenanceHealth::OK)
        ->and($check['age_hours'])->toBe(0);
});

it('alarms when ingest has been active past the freshness budget with no aggregates at all', function (int $oldestHoursAgo, string $status): void {
    activeIngest();
    RawEvent::factory()->create(['occurred_at' => now()->subHours($oldestHoursAgo)]);

    expect(app(MaintenanceHealth::class)->report()['checks']['aggregate_freshness']['status'])->toBe($status);
})->with([
    'within budget' => [6, MaintenanceHealth::OK],
    'past budget' => [7, MaintenanceHealth::ALARM],
]);

it('exits non-zero from hone:health when any check alarms', function (): void {
    app(MaintenanceMarkers::class)->putTimestamp(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS, CarbonImmutable::now()->subDays(17));

    $this->artisan('hone:health', ['--json' => true])
        ->expectsOutputToContain('"healthy": false')
        ->assertExitCode(1);
});

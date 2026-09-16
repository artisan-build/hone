<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Operator-facing maintenance invariants, each judged against its own configured budget.
 *
 * The retention and aggregate checks only alarm while ingest is active, so an idle or brand-new
 * install with legitimately no recent data reads healthy rather than alarming forever.
 */
final class MaintenanceHealth
{
    public const OK = 'ok';

    public const ALARM = 'alarm';

    public const IDLE = 'idle';

    public function __construct(private readonly MaintenanceMarkers $markers) {}

    /**
     * @return array{healthy: bool, ingest_active: bool, checks: array{maintenance: array{status: string, last_success_at: string|null, age_minutes: int|null, budget_minutes: int}, retention: array{status: string, oldest_occurred_at: string|null, age_hours: int|null, budget_hours: int}, aggregate_freshness: array{status: string, newest_bucket_date: string|null, age_hours: int|null, budget_hours: int}}}
     */
    public function report(): array
    {
        $now = CarbonImmutable::now('UTC');
        $ingestActive = DB::connection('hone')->table('raw_events')
            ->where('occurred_at', '>=', $now->subMinutes((int) config('hone-server.health.ingest_active_minutes', 60)))
            ->exists();
        $oldestRaw = $this->timestampOrNull(DB::connection('hone')->table('raw_events')->min('occurred_at'));

        $checks = [
            'maintenance' => $this->maintenanceCheck($now, $ingestActive, $oldestRaw),
            'retention' => $this->retentionCheck($now, $ingestActive, $oldestRaw),
            'aggregate_freshness' => $this->aggregateFreshnessCheck($now, $ingestActive, $oldestRaw),
        ];

        return [
            'healthy' => ! in_array(self::ALARM, array_column($checks, 'status'), true),
            'ingest_active' => $ingestActive,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{status: string, last_success_at: string|null, age_minutes: int|null, budget_minutes: int}
     */
    private function maintenanceCheck(CarbonImmutable $now, bool $ingestActive, ?CarbonImmutable $oldestRaw): array
    {
        $budget = (int) config('hone-server.health.maintenance_max_age_minutes', 150);
        $lastSuccess = $this->markers->timestamp(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS);

        if ($lastSuccess instanceof CarbonImmutable) {
            $age = $this->minutesBetween($lastSuccess, $now);
            $status = $age > $budget ? self::ALARM : self::OK;
        } else {
            // Never succeeded: only alarm once data has been waiting on maintenance for longer than the budget.
            $age = null;
            $status = $ingestActive && $oldestRaw instanceof CarbonImmutable && $this->minutesBetween($oldestRaw, $now) > $budget
                ? self::ALARM
                : self::OK;
        }

        return [
            'status' => $status,
            'last_success_at' => $lastSuccess?->toIso8601ZuluString(),
            'age_minutes' => $age,
            'budget_minutes' => $budget,
        ];
    }

    /**
     * @return array{status: string, oldest_occurred_at: string|null, age_hours: int|null, budget_hours: int}
     */
    private function retentionCheck(CarbonImmutable $now, bool $ingestActive, ?CarbonImmutable $oldestRaw): array
    {
        $budget = (int) config('hone-server.retention.raw_hours', 72) + (int) config('hone-server.health.retention_grace_hours', 24);
        $age = $oldestRaw instanceof CarbonImmutable ? intdiv($this->minutesBetween($oldestRaw, $now), 60) : null;

        return [
            'status' => match (true) {
                ! $ingestActive => self::IDLE,
                $oldestRaw instanceof CarbonImmutable && $this->minutesBetween($oldestRaw, $now) > $budget * 60 => self::ALARM,
                default => self::OK,
            },
            'oldest_occurred_at' => $oldestRaw?->toIso8601ZuluString(),
            'age_hours' => $age,
            'budget_hours' => $budget,
        ];
    }

    /**
     * Age is measured from the end of the newest bucket day, so a current partial bucket reads zero.
     *
     * @return array{status: string, newest_bucket_date: string|null, age_hours: int|null, budget_hours: int}
     */
    private function aggregateFreshnessCheck(CarbonImmutable $now, bool $ingestActive, ?CarbonImmutable $oldestRaw): array
    {
        $budget = (int) config('hone-server.health.aggregate_max_age_hours', 6);
        $newestBucket = DB::connection('hone')->table('aggregates')->max('bucket_date');
        $staleSince = $newestBucket === null
            ? $oldestRaw
            : CarbonImmutable::parse((string) $newestBucket, 'UTC')->startOfDay()->addDay();
        $ageMinutes = $staleSince instanceof CarbonImmutable ? max(0, $this->minutesBetween($staleSince, $now)) : null;

        return [
            'status' => match (true) {
                ! $ingestActive => self::IDLE,
                $ageMinutes !== null && $ageMinutes > $budget * 60 => self::ALARM,
                default => self::OK,
            },
            'newest_bucket_date' => $newestBucket === null ? null : CarbonImmutable::parse((string) $newestBucket)->toDateString(),
            'age_hours' => $newestBucket === null || $ageMinutes === null ? null : intdiv($ageMinutes, 60),
            'budget_hours' => $budget,
        ];
    }

    private function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }

    private function timestampOrNull(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc();
    }
}

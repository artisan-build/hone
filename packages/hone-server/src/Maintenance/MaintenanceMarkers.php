<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Durable maintenance state on the telemetry connection.
 *
 * The rollup watermark is the prune boundary: every raw event that occurred before it has been
 * aggregated, so pruning below it never destroys data the aggregates do not already hold.
 */
final class MaintenanceMarkers
{
    public const ROLLUP_WATERMARK = 'rollup.watermark';

    public const MAINTAIN_LAST_SUCCESS = 'maintain.last_success_at';

    public const MAINTAIN_LAST_FAILURE = 'maintain.last_failure_at';

    public const MAINTAIN_LAST_FAILURE_REASON = 'maintain.last_failure_reason';

    public function get(string $key): ?string
    {
        $value = DB::connection('hone')->table('maintenance_markers')->where('key', $key)->value('value');

        return $value === null ? null : (string) $value;
    }

    public function timestamp(string $key): ?CarbonImmutable
    {
        $value = $this->get($key);

        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    public function put(string $key, string $value): void
    {
        DB::connection('hone')->table('maintenance_markers')->upsert(
            [['key' => $key, 'value' => $value, 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
    }

    public function putTimestamp(string $key, CarbonImmutable $timestamp): void
    {
        $this->put($key, $timestamp->utc()->toIso8601ZuluString('microsecond'));
    }

    public function forget(string $key): void
    {
        DB::connection('hone')->table('maintenance_markers')->where('key', $key)->delete();
    }

    public function rollupWatermark(): ?CarbonImmutable
    {
        return $this->timestamp(self::ROLLUP_WATERMARK);
    }

    /**
     * Record that raw events occurring in [`$coveredFrom`, `$coveredUntil`) have been aggregated.
     *
     * The watermark only moves across contiguous coverage: a run whose range starts after the current
     * watermark leaves a gap of unaggregated events, so claiming it would let prune delete them.
     */
    public function advanceRollupWatermark(CarbonImmutable $coveredFrom, CarbonImmutable $coveredUntil): bool
    {
        $watermark = $this->rollupWatermark();

        if ($watermark === null) {
            $olderEventsExist = DB::connection('hone')->table('raw_events')
                ->where('occurred_at', '<', $coveredFrom)
                ->exists();

            if ($olderEventsExist) {
                return false;
            }
        } elseif ($coveredFrom->greaterThan($watermark) || $coveredUntil->lessThanOrEqualTo($watermark)) {
            return false;
        }

        $this->putTimestamp(self::ROLLUP_WATERMARK, $coveredUntil);

        return true;
    }

    /**
     * Carry prune forward across the upgrade from the whole-table rollup.
     *
     * That rollup aggregated every retained raw event on each run, so its last successful write time
     * (the newest `aggregates.updated_at`) is a sound watermark. Without this seed an existing install
     * would stop pruning raw events on upgrade until someone back-filled its whole retention window.
     */
    public function seedRollupWatermarkFromLegacyRollup(): void
    {
        if ($this->rollupWatermark() !== null) {
            return;
        }

        $lastLegacyWrite = DB::connection('hone')->table('aggregates')->max('updated_at');

        if ($lastLegacyWrite === null) {
            return;
        }

        $this->putTimestamp(self::ROLLUP_WATERMARK, CarbonImmutable::parse((string) $lastLegacyWrite));
    }
}

<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\ActivityTimelineRollup;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Maintenance\RawEventRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RollupCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:rollup';

    protected $description = 'Roll raw Hone events into daily aggregate metrics and per-minute activity buckets.';

    /**
     * Re-aggregate the current bucket plus the trailing late-arrival window.
     *
     * On upgrade, the first activity rollup also covers all retained raw events before its watermark
     * allows pruning. Older aggregate ranges are rebuilt explicitly with `hone:backfill`.
     */
    public function handle(
        RawEventRollup $rollup,
        ActivityTimelineRollup $activityTimelineRollup,
        MaintenanceMarkers $markers,
    ): int {
        $startedAt = CarbonImmutable::now('UTC');
        $lateArrivalHours = max(0, (int) config('hone-server.rollup.late_arrival_hours', 24));
        $firstDay = $startedAt->subHours($lateArrivalHours)->startOfDay();

        $groups = 0;
        $rows = 0;
        $activityBuckets = 0;
        $activityFirstDay = $this->activityFirstDay($firstDay, $markers);

        for ($day = $activityFirstDay; $day->lessThanOrEqualTo($startedAt); $day = $day->addDay()) {
            $result = $activityTimelineRollup->rollupDay($day, $startedAt);
            $activityBuckets += $result['buckets'];
        }

        for ($day = $firstDay; $day->lessThanOrEqualTo($startedAt); $day = $day->addDay()) {
            $result = $rollup->rollupDay($day, $startedAt);
            $groups += $result['groups'];
            $rows += $result['rows'];
        }

        if (! $markers->advanceRollupWatermark($firstDay, $startedAt)) {
            $watermark = $markers->rollupWatermark();

            $this->warn(sprintf(
                'Rollup watermark not advanced: raw events before %s may be unaggregated (watermark %s). Prune retains them until hone:backfill covers the gap.',
                $firstDay->toIso8601ZuluString(),
                $watermark?->toIso8601ZuluString() ?? 'unset',
            ));
        }

        if (! $markers->advanceActivityRollupWatermark($activityFirstDay, $startedAt)) {
            $watermark = $markers->activityRollupWatermark();

            $this->warn(sprintf(
                'Activity rollup watermark not advanced: raw events before %s may be unbucketed (watermark %s). Prune retains them until hone:backfill covers the gap.',
                $activityFirstDay->toIso8601ZuluString(),
                $watermark?->toIso8601ZuluString() ?? 'unset',
            ));
        }

        $this->info(sprintf(
            'Processed %d groups and %d activity buckets from %s; upserted %d aggregate rows.',
            $groups,
            $activityBuckets,
            $firstDay->toDateString(),
            $rows,
        ));

        return self::SUCCESS;
    }

    private function activityFirstDay(CarbonImmutable $firstDay, MaintenanceMarkers $markers): CarbonImmutable
    {
        if ($markers->activityRollupWatermark() !== null) {
            return $firstDay;
        }

        $oldestRawEvent = DB::connection('hone')->table('raw_events')->min('occurred_at');

        if ($oldestRawEvent === null) {
            return $firstDay;
        }

        return CarbonImmutable::parse((string) $oldestRawEvent)->utc()->startOfDay()->min($firstDay);
    }
}

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

    protected $description = 'Roll closed hours of raw Hone events into daily aggregates and activity buckets.';

    public function handle(
        RawEventRollup $rollup,
        ActivityTimelineRollup $activityTimelineRollup,
        MaintenanceMarkers $markers,
    ): int {
        $startedAt = CarbonImmutable::now('UTC');
        $closedUntil = $startedAt->startOfHour();
        $rollupFrom = $this->rangeStart($markers->rollupWatermark(), $closedUntil);
        $activityFrom = $this->rangeStart($markers->activityRollupWatermark(), $closedUntil);
        $mergeableFrom = $this->mergeableFrom($markers, $rollupFrom);
        $groups = 0;
        $rows = 0;
        $activityBuckets = 0;

        for ($from = $activityFrom; $from->lessThan($closedUntil); $from = $until) {
            $until = $from->startOfHour()->addHour()->min($closedUntil);
            $result = $activityTimelineRollup->rollupRange($from, $until, $startedAt);
            $activityBuckets += $result['buckets'];

            if (! $markers->advanceActivityRollupWatermark($from, $until)) {
                $this->warn('Activity rollup watermark did not advance; prune remains behind the gap.');

                break;
            }
        }

        for ($from = $rollupFrom; $from->lessThan($closedUntil); $from = $until) {
            $until = $from->startOfHour()->addHour()->min($closedUntil);
            $result = $rollup->rollupRange($from, $until, $startedAt);
            $groups += $result['groups'];
            $bucketDay = $from->startOfDay();

            if ($bucketDay->greaterThanOrEqualTo($mergeableFrom)) {
                $dailyResult = $rollup->rollupDaily($bucketDay, $startedAt);
                $rows += $dailyResult['rows'];
            }

            if (! $markers->advanceRollupWatermark($from, $until)) {
                $this->warn('Aggregate rollup watermark did not advance; prune remains behind the gap.');

                break;
            }
        }

        if ($activityFrom->equalTo($closedUntil) && $markers->activityRollupWatermark() === null) {
            $markers->advanceActivityRollupWatermark($closedUntil, $closedUntil);
        }

        if ($rollupFrom->equalTo($closedUntil) && $markers->rollupWatermark() === null) {
            $markers->advanceRollupWatermark($closedUntil, $closedUntil);
        }

        $this->info(sprintf(
            'Processed %d aggregate groups and %d activity buckets through %s; upserted %d daily aggregate rows.',
            $groups,
            $activityBuckets,
            $closedUntil->toIso8601ZuluString(),
            $rows,
        ));

        return self::SUCCESS;
    }

    private function rangeStart(?CarbonImmutable $watermark, CarbonImmutable $closedUntil): CarbonImmutable
    {
        if ($watermark !== null) {
            return $watermark;
        }

        $oldestRawEvent = DB::connection('hone')->table('raw_events')->min('occurred_at');

        return $oldestRawEvent === null
            ? $closedUntil
            : CarbonImmutable::parse((string) $oldestRawEvent)->utc();
    }

    private function mergeableFrom(MaintenanceMarkers $markers, CarbonImmutable $rollupFrom): CarbonImmutable
    {
        $existing = $markers->rollupMergeableFrom();

        if ($existing !== null) {
            return $existing;
        }

        $legacyWatermark = $markers->rollupWatermark();
        $mergeableFrom = $legacyWatermark !== null && ! $legacyWatermark->equalTo($legacyWatermark->startOfDay())
            ? $legacyWatermark->startOfDay()->addDay()
            : $rollupFrom->startOfDay();

        $markers->putTimestamp(MaintenanceMarkers::ROLLUP_MERGEABLE_FROM, $mergeableFrom);

        return $mergeableFrom;
    }
}

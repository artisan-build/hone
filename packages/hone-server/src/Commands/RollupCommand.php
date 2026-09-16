<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Maintenance\RawEventRollup;
use Carbon\CarbonImmutable;

final class RollupCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:rollup';

    protected $description = 'Roll recent raw Hone events into daily aggregate metrics.';

    /**
     * Re-aggregate only the current bucket plus the trailing late-arrival window.
     *
     * Hourly work scales with ingest rate rather than retained history, so a stalled prune can no
     * longer grow the next run. Older ranges are rebuilt explicitly with `hone:backfill`.
     */
    public function handle(RawEventRollup $rollup, MaintenanceMarkers $markers): int
    {
        $startedAt = CarbonImmutable::now('UTC');
        $lateArrivalHours = max(0, (int) config('hone-server.rollup.late_arrival_hours', 24));
        $firstDay = $startedAt->subHours($lateArrivalHours)->startOfDay();

        $groups = 0;
        $rows = 0;

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

        $this->info(sprintf(
            'Processed %d groups from %s; upserted %d aggregate rows.',
            $groups,
            $firstDay->toDateString(),
            $rows,
        ));

        return self::SUCCESS;
    }
}

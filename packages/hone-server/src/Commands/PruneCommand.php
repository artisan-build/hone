<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PruneCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:prune';

    protected $description = 'Prune expired Hone raw events, samples, and aggregates.';

    /**
     * Raw events are pruned only below the rollup watermark: an event past retention that has not
     * been aggregated yet is kept, so a stalled rollup retains data instead of destroying it.
     */
    public function handle(MaintenanceMarkers $markers): int
    {
        $rawHours = (int) config('hone-server.retention.raw_hours', 72);
        $sampleDays = (int) config('hone-server.retention.sample_days', 7);
        $aggregateDays = (int) config('hone-server.retention.aggregate_days', 90);

        $retentionCutoff = CarbonImmutable::now('UTC')->subHours($rawHours);
        $watermark = $markers->rollupWatermark();
        $rawCutoff = $watermark === null ? null : $retentionCutoff->min($watermark);

        $rawDeleted = $rawCutoff === null ? 0 : DB::connection('hone')->table('raw_events')
            ->where('occurred_at', '<', $rawCutoff)
            ->delete();

        if ($rawCutoff === null || $rawCutoff->lessThan($retentionCutoff)) {
            $this->warn(sprintf(
                'Retaining raw events older than %s that are not yet aggregated (rollup watermark %s).',
                $retentionCutoff->toIso8601ZuluString(),
                $watermark?->toIso8601ZuluString() ?? 'unset',
            ));
        }

        $samplesDeleted = DB::connection('hone')->table('samples')
            ->where('occurred_at', '<', now()->subDays($sampleDays))
            ->delete();

        $aggregatesDeleted = DB::connection('hone')->table('aggregates')
            ->where('bucket_date', '<', now()->toImmutable()->startOfDay()->subDays($aggregateDays)->toDateString())
            ->delete();

        $this->info(sprintf(
            'Pruned %d raw events, %d samples, and %d aggregates.',
            $rawDeleted,
            $samplesDeleted,
            $aggregatesDeleted,
        ));

        return self::SUCCESS;
    }
}

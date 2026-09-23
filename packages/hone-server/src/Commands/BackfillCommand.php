<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\ActivityTimelineRollup;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Maintenance\RawEventRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BackfillCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:backfill
        {from : First UTC bucket date to rebuild (Y-m-d)}
        {to : Last UTC bucket date to rebuild, inclusive (Y-m-d)}
        {--restart : Ignore the saved checkpoint and rebuild the range from the first day}';

    protected $description = 'Rebuild aggregates and activity buckets for a historical range, one UTC day at a time.';

    public function handle(
        RawEventRollup $rollup,
        ActivityTimelineRollup $activityTimelineRollup,
        MaintenanceMarkers $markers,
    ): int {
        $from = $this->bucketDate('from');
        $to = $this->bucketDate('to');
        $startedAt = CarbonImmutable::now('UTC');

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable) {
            $this->error('Both from and to must be dates in Y-m-d format.');

            return self::INVALID;
        }

        if ($from->greaterThan($to) || $to->greaterThan($startedAt)) {
            $this->error('The range must run forwards and must not end after today.');

            return self::INVALID;
        }

        $checkpointKey = sprintf('backfill.%s.%s', $from->toDateString(), $to->toDateString());
        $activityCheckpointKey = sprintf('activity_backfill.%s.%s', $from->toDateString(), $to->toDateString());
        $checkpoint = $this->option('restart') ? null : $markers->get($checkpointKey);
        $activityCheckpoint = $this->option('restart') ? null : $markers->get($activityCheckpointKey);
        $aggregateResumeFrom = $checkpoint === null ? $from : CarbonImmutable::parse($checkpoint, 'UTC')->addDay();
        $activityResumeFrom = $activityCheckpoint === null ? $from : CarbonImmutable::parse($activityCheckpoint, 'UTC')->addDay();

        if ($this->hasUnbucketedEvents($from, $to->addDay())) {
            $activityResumeFrom = $from;
            $aggregateResumeFrom = $from;
        }

        $resumeFrom = $aggregateResumeFrom->min($activityResumeFrom);

        if ($aggregateResumeFrom->greaterThan($to) && $activityResumeFrom->greaterThan($to)) {
            $coveredUntil = $to->addDay()->min($startedAt);
            $markers->advanceRollupWatermark($from, $coveredUntil);
            $markers->advanceActivityRollupWatermark($from, $coveredUntil);

            $this->info(sprintf('Backfill %s to %s is already complete.', $from->toDateString(), $to->toDateString()));

            return self::SUCCESS;
        }

        for ($day = $resumeFrom; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $result = ['groups' => 0, 'rows' => 0];
            $activityResult = ['buckets' => 0];

            if ($day->greaterThanOrEqualTo($activityResumeFrom)) {
                $activityResult = $activityTimelineRollup->rollupDay($day, CarbonImmutable::now('UTC'));
                $markers->advanceActivityRollupWatermark($day, $day->addDay()->min($startedAt));
                $markers->put($activityCheckpointKey, $day->toDateString());
            }

            if ($day->greaterThanOrEqualTo($aggregateResumeFrom)) {
                $result = $rollup->rollupDay($day, CarbonImmutable::now('UTC'));
                $markers->advanceRollupWatermark($day, $day->addDay()->min($startedAt));
                $markers->put($checkpointKey, $day->toDateString());
            }

            $this->line(sprintf(
                '%s: %d groups, %d aggregate rows, %d activity buckets.',
                $day->toDateString(),
                $result['groups'],
                $result['rows'],
                $activityResult['buckets'],
            ));
        }

        $this->info(sprintf(
            'Backfilled %s to %s. Rollup watermark: %s.',
            $resumeFrom->toDateString(),
            $to->toDateString(),
            $markers->rollupWatermark()?->toIso8601ZuluString() ?? 'unset',
        ));

        return self::SUCCESS;
    }

    private function bucketDate(string $argument): ?CarbonImmutable
    {
        $value = $this->argument($argument);

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date instanceof CarbonImmutable && $date->toDateString() === $value ? $date : null;
    }

    private function hasUnbucketedEvents(CarbonImmutable $from, CarbonImmutable $until): bool
    {
        return DB::connection('hone')->table('raw_events')
            ->where('occurred_at', '>=', $from)
            ->where('occurred_at', '<', $until)
            ->whereNull('activity_bucketed_at')
            ->exists();
    }
}

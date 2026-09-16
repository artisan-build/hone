<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use ArtisanBuild\HoneServer\Maintenance\RawEventRollup;
use Carbon\CarbonImmutable;
use Throwable;

final class BackfillCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:backfill
        {from : First UTC bucket date to rebuild (Y-m-d)}
        {to : Last UTC bucket date to rebuild, inclusive (Y-m-d)}
        {--restart : Ignore the saved checkpoint and rebuild the range from the first day}';

    protected $description = 'Rebuild aggregates for an explicit historical range, one bucket day at a time, resuming from its checkpoint.';

    public function handle(RawEventRollup $rollup, MaintenanceMarkers $markers): int
    {
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
        $checkpoint = $this->option('restart') ? null : $markers->get($checkpointKey);
        $resumeFrom = $checkpoint === null ? $from : CarbonImmutable::parse($checkpoint, 'UTC')->addDay();

        if ($resumeFrom->greaterThan($to)) {
            $this->info(sprintf('Backfill %s to %s is already complete.', $from->toDateString(), $to->toDateString()));

            return self::SUCCESS;
        }

        for ($day = $resumeFrom; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $result = $rollup->rollupDay($day, CarbonImmutable::now('UTC'));
            $markers->put($checkpointKey, $day->toDateString());
            $markers->advanceRollupWatermark($day, $day->addDay()->min($startedAt));

            $this->line(sprintf('%s: %d groups, %d aggregate rows.', $day->toDateString(), $result['groups'], $result['rows']));
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
}

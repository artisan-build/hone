<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneCommand extends Command
{
    protected $signature = 'hone:prune';

    protected $description = 'Prune expired Hone raw events, samples, and aggregates.';

    public function handle(): int
    {
        $rawHours = (int) config('hone-server.retention.raw_hours', 72);
        $sampleDays = (int) config('hone-server.retention.sample_days', 7);
        $aggregateDays = (int) config('hone-server.retention.aggregate_days', 90);

        $rawDeleted = DB::connection('hone')->table('raw_events')
            ->where('occurred_at', '<', now()->subHours($rawHours))
            ->delete();

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

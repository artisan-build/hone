<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class MaintainCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:maintain';

    protected $description = 'Run the Hone rollup then prune, reporting any failure.';

    /**
     * Run every sub-command even when an earlier one fails.
     *
     * `Artisan::call()` rethrows command exceptions rather than returning a failure code, so each
     * sub-command is caught here; prune stays safe after a failed rollup because it never deletes
     * past the rollup watermark.
     */
    public function handle(MaintenanceMarkers $markers): int
    {
        $failures = [];

        foreach (['hone:rollup', 'hone:prune'] as $command) {
            try {
                $exitCode = Artisan::call($command);

                if ($exitCode !== self::SUCCESS) {
                    $failures[] = sprintf('%s exited with code %d.', $command, $exitCode);
                }
            } catch (Throwable $exception) {
                report($exception);

                $failures[] = sprintf('%s threw %s: %s', $command, $exception::class, $exception->getMessage());
            }
        }

        if ($failures === []) {
            $markers->putTimestamp(MaintenanceMarkers::MAINTAIN_LAST_SUCCESS, CarbonImmutable::now('UTC'));

            $this->info('Hone maintenance completed.');

            return self::SUCCESS;
        }

        foreach ($failures as $failure) {
            $this->error('Hone maintenance failed: '.$failure);
        }

        $markers->putTimestamp(MaintenanceMarkers::MAINTAIN_LAST_FAILURE, CarbonImmutable::now('UTC'));
        $markers->put(MaintenanceMarkers::MAINTAIN_LAST_FAILURE_REASON, implode("\n", $failures));

        return self::FAILURE;
    }
}

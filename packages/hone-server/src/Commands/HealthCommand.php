<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceHealth;

final class HealthCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:health {--json : Print the report as JSON}';

    protected $description = 'Check maintenance recency, raw retention age, and aggregate freshness; exits non-zero on any alarm.';

    public function handle(MaintenanceHealth $health): int
    {
        $report = $health->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $this->table(['Check', 'Status', 'Detail'], array_map(
                fn (string $check, array $result): array => [
                    $check,
                    $result['status'],
                    (string) json_encode(array_diff_key($result, ['status' => true])),
                ],
                array_keys($report['checks']),
                $report['checks'],
            ));
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}

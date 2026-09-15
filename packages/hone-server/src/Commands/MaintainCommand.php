<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use ArtisanBuild\BuiltForCloud\Commands\SystemAuthorityCommand;
use Illuminate\Support\Facades\Artisan;

final class MaintainCommand extends SystemAuthorityCommand
{
    protected $signature = 'hone:maintain';

    protected $description = 'Run the Hone rollup then prune, in order.';

    public function handle(): int
    {
        if (Artisan::call('hone:rollup') !== self::SUCCESS) {
            $this->error('Hone rollup failed; skipping prune.');

            return self::FAILURE;
        }

        return Artisan::call('hone:prune');
    }
}

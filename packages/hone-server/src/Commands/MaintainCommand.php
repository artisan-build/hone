<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

final class MaintainCommand extends Command
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

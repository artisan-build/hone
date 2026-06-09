<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Commands;

use Illuminate\Console\Command;

final class IssueTokenCommand extends Command
{
    protected $signature = 'hone:issue-token {app}';

    protected $description = 'Issue a Hone source application token and print its hashed registry entry.';

    public function handle(): int
    {
        $app = trim((string) $this->argument('app'));

        if ($app === '' || str_contains($app, '=') || str_contains($app, ',')) {
            $this->error('The app id must be non-empty and may not contain "=" or ",".');

            return self::FAILURE;
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $this->line('Plaintext token: '.$token);
        $this->line('SHA-256 hash: '.$hash);
        $this->line('HONE_APP_TOKENS entry: '.$app.'='.$hash);
        $this->line('For multiple apps, separate entries with commas.');

        return self::SUCCESS;
    }
}

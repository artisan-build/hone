<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Commands;

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Throwable;

final class UpdateCommand extends Command
{
    protected $signature = 'hone:update';

    protected $description = 'Check whether this client is compatible with the configured Hone server.';

    public function __construct(private readonly Factory $http)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = config('hone.url');

        if (blank($url)) {
            $this->error('HONE_URL is not configured. Set HONE_URL before running hone:update.');

            return self::FAILURE;
        }

        try {
            $response = $this->http
                ->withToken((string) config('hone.token'))
                ->connectTimeout((float) config('hone.connect_timeout'))
                ->timeout((float) config('hone.timeout'))
                ->get($this->capabilitiesUrl((string) $url));
        } catch (Throwable $e) {
            $this->error('Could not reach your Hone server: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Could not fetch Hone server capabilities. HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        $capabilities = $response->json('envelope');

        if (! is_array($capabilities) || ! isset($capabilities['min_major'], $capabilities['max_major']) || ! is_int($capabilities['min_major']) || ! is_int($capabilities['max_major'])) {
            $this->error('Hone server returned invalid capabilities JSON.');

            return self::FAILURE;
        }

        $current = Envelope::VERSION;

        if ($current > $capabilities['max_major']) {
            $this->error("Your apps are ahead of your Hone server (it supports up to v{$capabilities['max_major']}; you have v{$current}). Update your Hone app first, then re-run hone:update.");

            return self::FAILURE;
        }

        if ($current < $capabilities['min_major']) {
            $this->warn("Your Hone server expects envelope v{$capabilities['min_major']} or newer; you have v{$current}. Update your Hone clients.");

            return self::SUCCESS;
        }

        $this->info("Your Hone server understands envelope v{$current}. You're good.");

        return self::SUCCESS;
    }

    private function capabilitiesUrl(string $url): string
    {
        $url = rtrim($url, '/');

        if (str_ends_with($url, '/ingest')) {
            $url = substr($url, 0, -strlen('/ingest'));
        }

        return $url.'/capabilities';
    }
}

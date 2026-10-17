<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Commands;

use ArtisanBuild\HoneClient\HoneEndpoint;
use ArtisanBuild\HoneClient\IngestDiscovery;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory;
use Throwable;

final class RefreshIngestDiscoveryCommand extends Command
{
    protected $signature = 'hone:refresh-ingest';

    protected $description = 'Refresh the cached ingest URL advertised by the configured Hone server.';

    public function __construct(
        private readonly Factory $http,
        private readonly HoneEndpoint $endpoint,
        private readonly IngestDiscovery $discovery,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->discovery->enabled()) {
            $this->info('Hone ingest discovery is disabled; using the configured fallback.');

            return self::SUCCESS;
        }

        if (! $this->endpoint->isConfigured() || blank(config('hone.token'))) {
            $this->error('Hone ingest discovery requires HONE_URL and HONE_TOKEN.');

            return self::FAILURE;
        }

        try {
            $response = $this->http
                ->connectTimeout((float) config('hone.console_connect_timeout'))
                ->timeout((float) config('hone.console_timeout'))
                ->get($this->endpoint->capabilitiesUrl());
        } catch (Throwable) {
            $this->error('Could not reach the Hone capabilities endpoint.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Could not fetch Hone server capabilities. HTTP '.$response->status().'.');

            return self::FAILURE;
        }

        try {
            $url = $response->json('ingest.url');
        } catch (Throwable) {
            $url = null;
        }

        if (! is_string($url) || ! $this->endpoint->acceptsDiscoveredUrl($url)) {
            $this->error('Hone server returned an invalid ingest URL.');

            return self::FAILURE;
        }

        if (! $this->discovery->remember($url)) {
            $this->error('Could not cache the discovered Hone ingest URL; using the configured fallback.');

            return self::FAILURE;
        }

        $this->info('Hone ingest URL discovery refreshed.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

use ArtisanBuild\HoneServer\Support\AdvertisedIngestUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class IngestTargetHealth
{
    public function __construct(private readonly AdvertisedIngestUrl $ingestUrl) {}

    /**
     * @return array{status: string, health_url: string|null, http_status: int|null, reason: string}
     */
    public function check(): array
    {
        $healthUrl = $this->healthUrl($this->ingestUrl->resolve());

        if ($healthUrl === null) {
            return $this->result(null, null, 'invalid_advertised_url');
        }

        try {
            $response = Http::connectTimeout(max(0.05, (float) config('hone-server.health.ingest_connect_timeout', 1)))
                ->timeout(max(0.05, (float) config('hone-server.health.ingest_timeout', 3)))
                ->withoutRedirecting()
                ->get($healthUrl);
        } catch (ConnectionException) {
            return $this->result($healthUrl, null, 'connection_failed');
        }

        return $this->result($healthUrl, $response->status(), $response->status() === 200 ? 'reachable' : 'unexpected_status');
    }

    private function healthUrl(string $advertisedUrl): ?string
    {
        if (filter_var($advertisedUrl, FILTER_VALIDATE_URL) === false || preg_match('/[\x00-\x20\x7f\\\\]/', $advertisedUrl)) {
            return null;
        }

        $parts = parse_url($advertisedUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '').'/up';
    }

    /**
     * @return array{status: string, health_url: string|null, http_status: int|null, reason: string}
     */
    private function result(?string $healthUrl, ?int $httpStatus, string $reason): array
    {
        return [
            'status' => $httpStatus === 200 ? MaintenanceHealth::OK : MaintenanceHealth::ALARM,
            'health_url' => $healthUrl,
            'http_status' => $httpStatus,
            'reason' => $reason,
        ];
    }
}

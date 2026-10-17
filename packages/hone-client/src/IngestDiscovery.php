<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

final readonly class IngestDiscovery
{
    public const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private HoneEndpoint $endpoint,
        private ?CacheRepository $cache,
        private bool $enabled,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function destination(): string
    {
        if (! $this->enabled || $this->cache === null) {
            return $this->endpoint->ingestUrl();
        }

        try {
            $url = $this->cache->get($this->endpoint->discoveryCacheKey());
        } catch (Throwable) {
            return $this->endpoint->ingestUrl();
        }

        if (! is_string($url) || ! $this->endpoint->acceptsDiscoveredUrl($url)) {
            return $this->endpoint->ingestUrl();
        }

        return $url;
    }

    public function remember(string $url): bool
    {
        if (! $this->enabled || $this->cache === null || ! $this->endpoint->acceptsDiscoveredUrl($url)) {
            return false;
        }

        try {
            return $this->cache->put(
                $this->endpoint->discoveryCacheKey(),
                $url,
                self::CACHE_TTL_SECONDS,
            ) !== false;
        } catch (Throwable) {
            return false;
        }
    }
}

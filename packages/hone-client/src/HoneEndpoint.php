<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

final readonly class HoneEndpoint
{
    public function __construct(private string $configuredUrl) {}

    public function isConfigured(): bool
    {
        return ! blank($this->configuredUrl);
    }

    public function ingestUrl(): string
    {
        $path = parse_url($this->configuredUrl, PHP_URL_PATH);
        $suffixOffset = strcspn($this->configuredUrl, '?#');
        $url = rtrim(substr($this->configuredUrl, 0, $suffixOffset), '/');
        $suffix = substr($this->configuredUrl, $suffixOffset);

        if (is_string($path) && $path !== '' && $path !== '/') {
            return $url.$suffix;
        }

        return $url.'/ingest'.$suffix;
    }

    public function capabilitiesUrl(): string
    {
        $url = rtrim($this->configuredUrl, '/');

        if (str_ends_with($url, '/ingest')) {
            $url = substr($url, 0, -strlen('/ingest'));
        }

        return $url.'/capabilities';
    }

    public function discoveryCacheKey(): string
    {
        return 'hone-client:discovered-ingest-url:'.hash('sha256', $this->configuredUrl);
    }

    public function acceptsDiscoveredUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;
        $configuredScheme = strtolower((string) parse_url($this->configuredUrl, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && ($configuredScheme !== 'https' || $scheme === 'https')
            && is_string($host)
            && $host !== ''
            && ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts)
            && ! array_key_exists('fragment', $parts);
    }
}

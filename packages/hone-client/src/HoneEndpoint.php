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

        if (is_string($path) && $path !== '' && $path !== '/') {
            return $this->configuredUrl;
        }

        $suffixOffset = strcspn($this->configuredUrl, '?#');
        $url = rtrim(substr($this->configuredUrl, 0, $suffixOffset), '/');
        $suffix = substr($this->configuredUrl, $suffixOffset);

        return $url.'/ingest'.$suffix;
    }

    public function capabilitiesUrl(): string
    {
        $components = parse_url($this->configuredUrl);

        if (! is_array($components)) {
            return rtrim($this->configuredUrl, '/').'/capabilities';
        }

        $path = rtrim((string) ($components['path'] ?? ''), '/');

        if (str_ends_with($path, '/ingest')) {
            $path = substr($path, 0, -strlen('/ingest'));
        }

        $components['path'] = $path.'/capabilities';

        return $this->buildUrl($components);
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

    /**
     * @param  array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, query?: string, fragment?: string}  $components
     */
    private function buildUrl(array $components): string
    {
        $url = isset($components['scheme']) ? $components['scheme'].'://' : '';

        if (isset($components['user'])) {
            $url .= $components['user'];
            $url .= isset($components['pass']) ? ':'.$components['pass'] : '';
            $url .= '@';
        }

        $url .= $components['host'] ?? '';
        $url .= isset($components['port']) ? ':'.$components['port'] : '';
        $url .= $components['path'] ?? '';
        $url .= array_key_exists('query', $components) ? '?'.$components['query'] : '';
        $url .= array_key_exists('fragment', $components) ? '#'.$components['fragment'] : '';

        return $url;
    }
}

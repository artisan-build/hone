<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Contracts\NameserverResolver;
use ArtisanBuild\HoneServer\Models\RequestActivityBucket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('edge_profile')]
#[Description('Report Cloudflare nameserver detection and observed per-route response cacheability facts. Detection uses DNS NS records only, never request headers.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class EdgeProfileTool extends Tool
{
    use AdvertisesToolClassification;

    public const MAX_ROUTES = 100;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['required', 'string', 'max:255'],
        ]);
        $query = RequestActivityBucket::query()->toBase()->where('app', $validated['app']);
        $observedHost = (clone $query)
            ->whereNotNull('host')
            ->orderByDesc('bucket_minute')
            ->orderByDesc('id')
            ->value('host');
        $domain = $this->domain(is_string($observedHost) ? $observedHost : $validated['app']);
        $nameservers = $domain === null ? [] : app(NameserverResolver::class)->resolve($domain);
        $nameservers = collect($nameservers)
            ->filter(static fn (mixed $nameserver): bool => is_string($nameserver) && $nameserver !== '')
            ->map(static fn (string $nameserver): string => strtolower(rtrim($nameserver, '.')))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $onCloudflare = collect($nameservers)->contains(static fn (string $nameserver): bool => $nameserver === 'ns.cloudflare.com'
            || str_ends_with($nameserver, '.ns.cloudflare.com'));
        $totalRoutes = (clone $query)->distinct()->count('path');
        $routeTotals = (clone $query)
            ->select('path')
            ->selectRaw('sum(hits)::bigint as hits')
            ->groupBy('path')
            ->orderByDesc('hits')
            ->orderBy('path')
            ->limit(self::MAX_ROUTES)
            ->get();
        $paths = $routeTotals->pluck('path')->map(static fn (mixed $path): string => (string) $path)->all();
        $factsByPath = $paths === [] ? collect() : (clone $query)
            ->select(['path', 'actor', 'sets_cookie', 'cache_control', 'vary'])
            ->selectRaw('sum(hits)::bigint as hits')
            ->whereIn('path', $paths)
            ->groupBy('path', 'actor', 'sets_cookie', 'cache_control', 'vary')
            ->orderBy('path')
            ->orderBy('actor')
            ->orderBy('sets_cookie')
            ->orderBy('cache_control')
            ->orderBy('vary')
            ->get()
            ->groupBy('path');
        $observedWindow = (clone $query)
            ->selectRaw('min(bucket_minute) as first_seen, max(bucket_minute) as last_seen')
            ->first();
        $routes = $routeTotals->map(function (object $route) use ($factsByPath): array {
            $path = (string) $route->path;
            /** @var Collection<int, object> $facts */
            $facts = $factsByPath->get($path, collect());

            return [
                'path' => $path,
                'hits' => (int) $route->hits,
                'varies_by_user' => $this->variesByUser($facts),
                'response_facts' => $facts->map(static fn (object $fact): array => [
                    'actor' => (string) $fact->actor,
                    'sets_cookie' => $fact->sets_cookie === null ? null : (bool) $fact->sets_cookie,
                    'cache_control' => $fact->cache_control === null ? null : (string) $fact->cache_control,
                    'vary' => $fact->vary === null ? null : (string) $fact->vary,
                    'hits' => (int) $fact->hits,
                ])->values()->all(),
            ];
        })->all();

        return Response::json([
            'app' => $validated['app'],
            'domain' => $domain,
            'domain_source' => is_string($observedHost) ? 'observed_request_host' : ($domain === null ? null : 'app_id'),
            'nameservers' => $nameservers,
            'on_cloudflare' => $onCloudflare,
            'cloudflare_detection' => 'true only when a normalized DNS NS name is ns.cloudflare.com or ends in .ns.cloudflare.com; request headers are ignored',
            'observation_window' => [
                'from' => $observedWindow?->first_seen === null ? null : (string) $observedWindow->first_seen,
                'to' => $observedWindow?->last_seen === null ? null : (string) $observedWindow->last_seen,
                'bounds' => 'all retained request activity buckets',
            ],
            'limits' => ['max_routes' => self::MAX_ROUTES],
            'total_routes' => $totalRoutes,
            'truncated' => $totalRoutes > self::MAX_ROUTES,
            'routes' => $routes,
        ]);
    }

    /**
     * @param  Collection<int, object>  $facts
     */
    private function variesByUser(Collection $facts): ?bool
    {
        $signatures = $facts->groupBy('actor')->map(static fn (Collection $actorFacts): array => $actorFacts
            ->map(static fn (object $fact): string => json_encode([
                $fact->sets_cookie,
                $fact->cache_control,
                $fact->vary,
            ], JSON_THROW_ON_ERROR))
            ->unique()
            ->sort()
            ->values()
            ->all());

        if (! $signatures->has('guest') || ! $signatures->has('human')) {
            return null;
        }

        return $signatures->get('guest') !== $signatures->get('human');
    }

    private function domain(string $candidate): ?string
    {
        $domain = strtolower(rtrim(trim($candidate), '.'));

        if (! str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $domain;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Required app id whose observed domain and routes should be profiled.')->required(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsActivityTimelineWindow;
use ArtisanBuild\HoneServer\Models\RequestActivityBucket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('guest_db_routes')]
#[Description('List guest routes that ran database queries, with hit counts and exact observed response cookie, Cache-Control, and Vary facts.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class GuestDbRoutesTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsActivityTimelineWindow;

    public const MAX_ROUTES = 100;

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->activityWindowRules());
        $window = $this->activityWindow($validated);
        $query = RequestActivityBucket::query()
            ->toBase()
            ->where('app', $validated['app'])
            ->where('actor', 'guest')
            ->where('ran_queries', true)
            ->whereBetween('bucket_minute', [$window['from']->toIso8601String(), $window['to']->toIso8601String()]);
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
            ->select(['path', 'sets_cookie', 'cache_control', 'vary'])
            ->selectRaw('sum(hits)::bigint as hits')
            ->whereIn('path', $paths)
            ->groupBy('path', 'sets_cookie', 'cache_control', 'vary')
            ->orderBy('path')
            ->orderBy('sets_cookie')
            ->orderBy('cache_control')
            ->orderBy('vary')
            ->get()
            ->groupBy('path');

        $routes = $routeTotals->map(static function (object $route) use ($factsByPath): array {
            $path = (string) $route->path;

            return [
                'path' => $path,
                'hits' => (int) $route->hits,
                'response_facts' => $factsByPath->get($path, collect())->map(static fn (object $fact): array => [
                    'sets_cookie' => $fact->sets_cookie === null ? null : (bool) $fact->sets_cookie,
                    'cache_control' => $fact->cache_control === null ? null : (string) $fact->cache_control,
                    'vary' => $fact->vary === null ? null : (string) $fact->vary,
                    'hits' => (int) $fact->hits,
                ])->values()->all(),
            ];
        })->all();

        return Response::json([
            'app' => $validated['app'],
            'window' => [
                'from' => $window['from']->toIso8601ZuluString(),
                'to' => $window['to']->toIso8601ZuluString(),
                'bounds' => 'inclusive activity buckets',
            ],
            'limits' => ['max_routes' => self::MAX_ROUTES],
            'total_routes' => $totalRoutes,
            'truncated' => $totalRoutes > self::MAX_ROUTES,
            'routes' => $routes,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Required app id to analyze.')->required(),
            'from' => $this->activityTimestampSchema($schema, 'start'),
            'to' => $this->activityTimestampSchema($schema, 'end'),
        ];
    }
}

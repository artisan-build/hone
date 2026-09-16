<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsRawEventLookback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List recent non-null deploy ids with first and last raw event timestamps within a lookback window, optionally scoped to one app.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class DeploysTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsRawEventLookback;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
            ...$this->lookbackRules(),
        ]);
        $lookback = $this->lookback($validated);

        $deploys = DB::connection('hone')->table('raw_events')
            ->select('deploy')
            ->selectRaw('min(occurred_at) as first_seen')
            ->selectRaw('max(occurred_at) as last_seen')
            ->selectRaw('count(*) as row_count')
            ->whereNotNull('deploy')
            ->where('occurred_at', '>=', $lookback['since'])
            ->when(isset($validated['app']), fn (Builder $query) => $query->where('app', $validated['app']))
            ->groupBy('deploy')
            ->orderByDesc('last_seen')
            ->limit(25)
            ->get()
            ->map(fn (object $event): array => [
                'deploy' => (string) $event->deploy,
                'first_seen' => Carbon::parse((string) $event->first_seen)->toJSON(),
                'last_seen' => Carbon::parse((string) $event->last_seen)->toJSON(),
                'row_count' => (int) $event->row_count,
            ])
            ->all();

        return Response::json([
            'app' => $validated['app'] ?? null,
            'window' => ['hours' => $lookback['hours'], 'since' => $lookback['since']->toJSON()],
            'deploys' => $deploys,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Optional app id to scope the deploy list.'),
            'hours' => $this->lookbackSchema($schema),
        ];
    }
}

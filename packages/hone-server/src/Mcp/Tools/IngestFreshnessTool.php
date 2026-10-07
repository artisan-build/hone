<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceHealth;
use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsRawEventLookback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List each app with the latest raw event timestamp, cumulative client loss counters by app and deploy, aggregate freshness, and overall maintenance health.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class IngestFreshnessTool extends Tool
{
    use AdvertisesToolClassification;
    use AdvertisesToolEffect;
    use BoundsRawEventLookback;
    use RespectsEffectCeiling;

    public function handle(Request $request): Response
    {
        $lookback = $this->lookback($request->validate($this->lookbackRules()));

        $apps = DB::connection('hone')->table('raw_events')
            ->select('app')
            ->selectRaw('max(occurred_at) as latest_occurred_at')
            ->selectRaw('max(created_at) as latest_ingested_at')
            ->where('occurred_at', '>=', $lookback['since'])
            ->groupBy('app')
            ->orderBy('app')
            ->get()
            ->map(fn (object $event): array => [
                'app' => (string) $event->app,
                'latest_occurred_at' => Carbon::parse((string) $event->latest_occurred_at)->toJSON(),
                'latest_ingested_at' => Carbon::parse((string) $event->latest_ingested_at)->toJSON(),
            ])
            ->all();

        $health = app(MaintenanceHealth::class)->report();

        $lossCounters = DB::connection('hone')->table('ingest_counters')
            ->select(['app', 'deploy', 'overflow_dropped_records', 'failed_delivery_records'])
            ->orderBy('app')
            ->orderBy('deploy')
            ->get()
            ->map(fn (object $counter): array => [
                'app' => (string) $counter->app,
                'deploy' => $counter->deploy === null ? null : (string) $counter->deploy,
                'overflow_dropped_records' => (int) $counter->overflow_dropped_records,
                'failed_delivery_records' => (int) $counter->failed_delivery_records,
            ])
            ->all();

        return Response::json([
            'window' => ['hours' => $lookback['hours'], 'since' => $lookback['since']->toJSON()],
            'apps' => $apps,
            'loss_counters' => $lossCounters,
            'aggregate_freshness' => app(AggregateWindow::class)->freshness(null),
            'health' => [
                'status' => $health['healthy'] ? 'healthy' : 'unhealthy',
                'ingest_active' => $health['ingest_active'],
                'checks' => $health['checks'],
            ],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $this->lookbackSchema($schema),
        ];
    }
}

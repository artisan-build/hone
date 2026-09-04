<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List each app with the latest raw event timestamp seen by Hone.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class IngestFreshnessTool extends Tool
{
    use AdvertisesToolClassification;

    public function handle(Request $request): Response
    {
        $apps = DB::connection('hone')->table('raw_events')
            ->select('app')
            ->selectRaw('max(occurred_at) as latest_occurred_at')
            ->selectRaw('max(created_at) as latest_ingested_at')
            ->groupBy('app')
            ->orderBy('app')
            ->get()
            ->map(fn (object $event): array => [
                'app' => (string) $event->app,
                'latest_occurred_at' => Carbon::parse((string) $event->latest_occurred_at)->toJSON(),
                'latest_ingested_at' => Carbon::parse((string) $event->latest_ingested_at)->toJSON(),
            ])
            ->all();

        return Response::json(['apps' => $apps]);
    }
}

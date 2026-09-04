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

#[Description('List apps reporting telemetry to Hone with their latest raw event timestamp.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class ListAppsTool extends Tool
{
    use AdvertisesToolClassification;

    public function handle(Request $request): Response
    {
        $apps = DB::connection('hone')->table('raw_events')
            ->select('app')
            ->selectRaw('max(occurred_at) as last_seen')
            ->groupBy('app')
            ->orderBy('app')
            ->get()
            ->map(fn (object $event): array => [
                'app' => (string) $event->app,
                'last_seen' => Carbon::parse((string) $event->last_seen)->toJSON(),
            ])
            ->all();

        return Response::json(['apps' => $apps]);
    }
}

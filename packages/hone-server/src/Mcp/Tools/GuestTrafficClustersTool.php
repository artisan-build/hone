<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Support\GuestTrafficClusterAnalysis;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsActivityTimelineWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('guest_traffic_clusters')]
#[Description('Group guest traffic by path, User-Agent, and ASN, with request volume and compute awake minutes attributed after human traffic using the five-minute idle model.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class GuestTrafficClustersTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsActivityTimelineWindow;

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->activityWindowRules());
        $window = $this->activityWindow($validated);
        $analysis = app(GuestTrafficClusterAnalysis::class)->forApp(
            $validated['app'],
            $window['from'],
            $window['to'],
        );

        return Response::json([
            'app' => $validated['app'],
            'window' => [
                'from' => $window['from']->toIso8601ZuluString(),
                'to' => $window['to']->toIso8601ZuluString(),
                'bounds' => 'inclusive activity buckets; attributed wake time may extend by the idle timeout',
                'compute_idle_minutes' => GuestTrafficClusterAnalysis::IDLE_MINUTES,
            ],
            'attribution_definition' => 'Each awake minute goes to human traffic first, then to the active guest cluster with the latest request; exact ties use path, User-Agent, and ASN order.',
            'limits' => ['max_clusters' => GuestTrafficClusterAnalysis::MAX_CLUSTERS],
            'total_clusters' => $analysis['total_clusters'],
            'truncated' => $analysis['truncated'],
            'clusters' => $analysis['clusters'],
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

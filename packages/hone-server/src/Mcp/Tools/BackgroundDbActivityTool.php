<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsActivityTimelineWindow;
use ArtisanBuild\HoneServer\Models\BackgroundActivityBucket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('background_db_activity')]
#[Description('List individual database-touching scheduled tasks and jobs by stable normalized name. Frequency is each activity\'s average run count per minute across the requested inclusive timeline window.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class BackgroundDbActivityTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsActivityTimelineWindow;

    public function handle(Request $request): Response
    {
        $validated = $request->validate($this->activityWindowRules());
        $window = $this->activityWindow($validated);
        $activities = BackgroundActivityBucket::query()
            ->toBase()
            ->select(['activity_type', 'identity'])
            ->selectRaw('sum(runs_with_queries) as runs')
            ->selectRaw('count(*) as active_minutes')
            ->where('app', $validated['app'])
            ->whereBetween('bucket_minute', [$window['from']->toIso8601String(), $window['to']->toIso8601String()])
            ->groupBy('activity_type', 'identity')
            ->orderBy('activity_type')
            ->orderBy('identity')
            ->get()
            ->map(fn (object $activity): array => [
                'class' => (string) $activity->activity_type,
                'name' => (string) $activity->identity,
                'runs' => (int) $activity->runs,
                'active_minutes' => (int) $activity->active_minutes,
                'runs_per_minute' => round((int) $activity->runs / $window['minutes'], 6),
            ])
            ->all();

        return Response::json([
            'app' => $validated['app'],
            'window' => [
                'from' => $window['from']->toIso8601ZuluString(),
                'to' => $window['to']->toIso8601ZuluString(),
                'bounds' => 'inclusive activity buckets',
                'minutes' => $window['minutes'],
            ],
            'background_classes' => ['scheduled', 'job'],
            'frequency_definition' => 'runs_per_minute is DB-touching runs divided by requested inclusive bucket-window minutes.',
            'activities' => $activities,
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

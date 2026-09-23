<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsActivityTimelineWindow;
use ArtisanBuild\HoneServer\Models\ActivityBucket;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('background_db_activity')]
#[Description('Return database-touching scheduled task and job runs for an app. Frequency is the average run count per minute across the requested inclusive activity-bucket window.')]
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
        $totals = ActivityBucket::query()
            ->toBase()
            ->selectRaw('coalesce(sum(scheduled_runs_with_queries), 0) as scheduled_runs')
            ->selectRaw('count(*) filter (where scheduled_runs_with_queries > 0) as scheduled_active_minutes')
            ->selectRaw('coalesce(sum(jobs_with_queries), 0) as job_runs')
            ->selectRaw('count(*) filter (where jobs_with_queries > 0) as job_active_minutes')
            ->where('app', $validated['app'])
            ->whereBetween('bucket_minute', [$window['from']->toIso8601String(), $window['to']->toIso8601String()])
            ->firstOrFail();

        $activities = [];

        foreach ([
            'scheduled' => [(int) $totals->scheduled_runs, (int) $totals->scheduled_active_minutes],
            'job' => [(int) $totals->job_runs, (int) $totals->job_active_minutes],
        ] as $activityClass => [$runs, $activeMinutes]) {
            if ($runs === 0) {
                continue;
            }

            $activities[] = [
                'class' => $activityClass,
                'runs' => $runs,
                'active_minutes' => $activeMinutes,
                'runs_per_minute' => round($runs / $window['minutes'], 6),
            ];
        }

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

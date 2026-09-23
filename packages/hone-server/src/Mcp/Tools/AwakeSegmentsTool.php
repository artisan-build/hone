<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Support\AwakeSegmentAnalysis;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsActivityTimelineWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('awake_segments')]
#[Description('Return compute and database awake segments from minute activity buckets. Every class reports partitioned minutes, which use human > guest > background precedence and sum to the segment duration, plus sustained_minutes, which is each class\'s independent overlapping coverage and does not sum to the duration. Background includes scheduled tasks and jobs.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class AwakeSegmentsTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsActivityTimelineWindow;

    public const MAX_IDLE_MINUTES = 1440;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            ...$this->activityWindowRules(),
            'compute_idle_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_IDLE_MINUTES],
            'db_idle_minutes' => ['required', 'integer', 'min:1', 'max:'.self::MAX_IDLE_MINUTES],
        ]);
        $window = $this->activityWindow($validated);
        $segments = app(AwakeSegmentAnalysis::class)->forApp(
            $validated['app'],
            $window['from'],
            $window['to'],
            (int) $validated['compute_idle_minutes'],
            (int) $validated['db_idle_minutes'],
        );

        return Response::json([
            'app' => $validated['app'],
            'window' => [
                'from' => $window['from']->toIso8601ZuluString(),
                'to' => $window['to']->toIso8601ZuluString(),
                'bounds' => 'inclusive activity buckets; segment ends may extend by the idle timeout',
                'compute_idle_minutes' => (int) $validated['compute_idle_minutes'],
                'db_idle_minutes' => (int) $validated['db_idle_minutes'],
            ],
            'metric_definitions' => [
                'segment_bounds' => 'Each segment is half-open [from, to): awake one second before to, idle exactly at to unless another activity interval starts there.',
                'minutes' => 'A partition of each segment using human > guest > background precedence; class totals equal segment duration.',
                'sustained_minutes' => 'Independent per-class awake coverage within the segment; classes may overlap, so totals need not equal segment duration.',
            ],
            'compute_segments' => $segments['compute'],
            'database_segments' => $segments['database'],
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
            'compute_idle_minutes' => $schema->integer()
                ->description('Required compute idle timeout in minutes.')
                ->min(1)
                ->max(self::MAX_IDLE_MINUTES)
                ->required(),
            'db_idle_minutes' => $schema->integer()
                ->description('Required database idle timeout in minutes.')
                ->min(1)
                ->max(self::MAX_IDLE_MINUTES)
                ->required(),
        ];
    }
}

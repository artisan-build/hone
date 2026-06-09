<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('query_metric')]
#[Description('Catch-all aggregate metric query for any Nightwatch record_type. Combines daily aggregates over a window; p95 and p99 use the worst daily percentile because percentiles cannot be averaged across days.')]
#[IsReadOnly]
final class QueryMetricTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'record_type' => ['required', 'string', 'max:255'],
            'metric' => ['required', 'string', Rule::in(AggregateWindow::METRICS)],
            'normalized_key' => ['nullable', 'string', 'max:2048'],
            'app' => ['nullable', 'string', 'max:255'],
            'deploy' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 7);

        return Response::json([
            'record_type' => $validated['record_type'],
            'window' => [
                'days' => $days,
                'metric' => $validated['metric'],
                'normalized_key' => $validated['normalized_key'] ?? null,
                'app' => $validated['app'] ?? null,
                'deploy' => $validated['deploy'] ?? null,
            ],
            'metrics' => app(AggregateWindow::class)->metric(
                $validated['record_type'],
                $validated['metric'],
                $validated['normalized_key'] ?? null,
                $validated['app'] ?? null,
                $validated['deploy'] ?? null,
                $days,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'record_type' => $schema->string()->description('Required telemetry record type, such as request, query, queued-job, or outgoing-request.'),
            'metric' => $schema->string()->description('Required metric: count, avg, max, p95, or p99.'),
            'normalized_key' => $schema->string()->description('Optional normalized key to scope the query.'),
            'app' => $schema->string()->description('Optional app id to scope the aggregate window.'),
            'deploy' => $schema->string()->description('Optional deploy id to scope the aggregate window.'),
            'days' => $schema->integer()->description('Trailing day window. Defaults to 7.'),
        ];
    }
}

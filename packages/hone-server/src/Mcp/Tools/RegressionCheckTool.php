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

#[Name('regression_check')]
#[Description('Compare one aggregate metric for a normalized key across recent deploys. p95 and p99 use the worst daily percentile per deploy because percentiles cannot be averaged across days.')]
#[IsReadOnly]
final class RegressionCheckTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'record_type' => ['required', 'string', 'max:255'],
            'normalized_key' => ['required', 'string', 'max:2048'],
            'metric' => ['required', 'string', Rule::in(AggregateWindow::METRICS)],
            'app' => ['nullable', 'string', 'max:255'],
            'deploys_limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $deploysLimit = (int) ($validated['deploys_limit'] ?? 5);

        return Response::json([
            'record_type' => $validated['record_type'],
            'normalized_key' => $validated['normalized_key'],
            'metric' => $validated['metric'],
            'app' => $validated['app'] ?? null,
            'deploys_limit' => $deploysLimit,
            'deploys' => app(AggregateWindow::class)->acrossDeploys(
                $validated['record_type'],
                $validated['normalized_key'],
                $validated['metric'],
                $validated['app'] ?? null,
                $deploysLimit,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'record_type' => $schema->string()->description('Required telemetry record type.'),
            'normalized_key' => $schema->string()->description('Required normalized key to compare across deploys.'),
            'metric' => $schema->string()->description('Required metric: count, avg, max, p95, or p99.'),
            'app' => $schema->string()->description('Optional app id to scope the deploy comparison.'),
            'deploys_limit' => $schema->integer()->description('Maximum recent deploys to return. Defaults to 5.'),
        ];
    }
}

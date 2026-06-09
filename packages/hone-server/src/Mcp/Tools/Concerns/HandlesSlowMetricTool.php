<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools\Concerns;

use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait HandlesSlowMetricTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
            'deploy' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'metric' => ['nullable', 'string', Rule::in(AggregateWindow::METRICS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $metric = (string) ($validated['metric'] ?? 'p95');
        $limit = (int) ($validated['limit'] ?? 10);

        return Response::json([
            'record_type' => $this->recordType(),
            'window' => [
                'days' => $days,
                'metric' => $metric,
                'app' => $validated['app'] ?? null,
                'deploy' => $validated['deploy'] ?? null,
                'limit' => $limit,
            ],
            'offenders' => app(AggregateWindow::class)->topOffenders(
                $this->recordType(),
                $days,
                $validated['app'] ?? null,
                $validated['deploy'] ?? null,
                $metric,
                $limit,
            ),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Optional app id to scope the aggregate window.'),
            'deploy' => $schema->string()->description('Optional deploy id to scope the aggregate window.'),
            'days' => $schema->integer()->description('Trailing day window. Defaults to 7.'),
            'metric' => $schema->string()->description('Sort metric: count, avg, max, p95, or p99. Defaults to p95.'),
            'limit' => $schema->integer()->description('Maximum offender rows to return. Defaults to 10.'),
        ];
    }

    abstract protected function recordType(): string;
}

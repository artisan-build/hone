<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('queue_throughput')]
#[Description('Return total queued job throughput with the top job normalized keys by count.')]
#[IsReadOnly]
final class QueueThroughputTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $limit = (int) ($validated['limit'] ?? 10);
        $window = app(AggregateWindow::class);

        return Response::json([
            'record_type' => 'queued-job',
            'window' => [
                'days' => $days,
                'app' => $validated['app'] ?? null,
                'limit' => $limit,
            ],
            'total' => $window->total('queued-job', $days, $validated['app'] ?? null),
            'jobs' => $window->topOffenders('queued-job', $days, $validated['app'] ?? null, null, 'count', $limit),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Optional app id to scope the aggregate window.'),
            'days' => $schema->integer()->description('Trailing day window. Defaults to 7.'),
            'limit' => $schema->integer()->description('Maximum job rows to return. Defaults to 10.'),
        ];
    }
}

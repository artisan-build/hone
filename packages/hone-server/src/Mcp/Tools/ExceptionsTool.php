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

#[Name('exceptions')]
#[Description('List exception normalized keys by volume with their most recent aggregate bucket date.')]
#[IsReadOnly]
final class ExceptionsTool extends Tool
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

        $rows = collect(app(AggregateWindow::class)->topOffenders('exception', $days, $validated['app'] ?? null, null, 'count', $limit))
            ->map(fn (array $row): array => [
                'normalized_key' => $row['normalized_key'],
                'count' => $row['count'],
                'last_seen' => $row['last_bucket_date'],
            ])
            ->all();

        return Response::json([
            'record_type' => 'exception',
            'window' => [
                'days' => $days,
                'app' => $validated['app'] ?? null,
                'limit' => $limit,
            ],
            'exceptions' => $rows,
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
            'limit' => $schema->integer()->description('Maximum exception rows to return. Defaults to 10.'),
        ];
    }
}

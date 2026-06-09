<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools\Concerns;

use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait HandlesTotalVolumeTool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 7);

        return Response::json([
            'record_type' => $this->recordType(),
            'window' => [
                'days' => $days,
                'app' => $validated['app'] ?? null,
            ],
            'total' => app(AggregateWindow::class)->total($this->recordType(), $days, $validated['app'] ?? null),
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
        ];
    }

    abstract protected function recordType(): string;
}

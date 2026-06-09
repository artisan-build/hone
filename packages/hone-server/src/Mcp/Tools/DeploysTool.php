<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List recent non-null deploy ids with first and last raw event timestamps, optionally scoped to one app.')]
#[IsReadOnly]
final class DeploysTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
        ]);

        $deploys = DB::connection('hone')->table('raw_events')
            ->select('deploy')
            ->selectRaw('min(occurred_at) as first_seen')
            ->selectRaw('max(occurred_at) as last_seen')
            ->selectRaw('count(*) as row_count')
            ->whereNotNull('deploy')
            ->when(isset($validated['app']), fn (Builder $query) => $query->where('app', $validated['app']))
            ->groupBy('deploy')
            ->orderByDesc('last_seen')
            ->limit(25)
            ->get()
            ->map(fn (object $event): array => [
                'deploy' => (string) $event->deploy,
                'first_seen' => Carbon::parse((string) $event->first_seen)->toJSON(),
                'last_seen' => Carbon::parse((string) $event->last_seen)->toJSON(),
                'row_count' => (int) $event->row_count,
            ])
            ->all();

        return Response::json([
            'app' => $validated['app'] ?? null,
            'deploys' => $deploys,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Optional app id to scope the deploy list.'),
        ];
    }
}

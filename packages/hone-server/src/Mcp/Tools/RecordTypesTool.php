<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\HoneServer\Mcp\Tools\Concerns\BoundsRawEventLookback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List raw telemetry record types and counts within a lookback window, optionally scoped to one app.')]
#[IsReadOnly]
#[ToolClassification(Classification::Content)]
final class RecordTypesTool extends Tool
{
    use AdvertisesToolClassification;
    use BoundsRawEventLookback;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'app' => ['nullable', 'string', 'max:255'],
            ...$this->lookbackRules(),
        ]);
        $lookback = $this->lookback($validated);

        $recordTypes = DB::connection('hone')->table('raw_events')
            ->select('record_type')
            ->selectRaw('count(*) as row_count')
            ->where('occurred_at', '>=', $lookback['since'])
            ->when(isset($validated['app']), fn (Builder $query) => $query->where('app', $validated['app']))
            ->groupBy('record_type')
            ->orderBy('record_type')
            ->get()
            ->map(fn (object $event): array => [
                'record_type' => (string) $event->record_type,
                'row_count' => (int) $event->row_count,
            ])
            ->all();

        return Response::json([
            'app' => $validated['app'] ?? null,
            'window' => ['hours' => $lookback['hours'], 'since' => $lookback['since']->toJSON()],
            'record_types' => $recordTypes,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'app' => $schema->string()->description('Optional app id to scope the record type counts.'),
            'hours' => $this->lookbackSchema($schema),
        ];
    }
}

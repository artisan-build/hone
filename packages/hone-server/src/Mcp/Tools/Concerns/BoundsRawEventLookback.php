<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * Bound raw event reads to a lookback window served by the `occurred_at` indexes.
 *
 * The default is the configured raw retention, which is all a healthy install holds, so a call with
 * no arguments answers the same question it always did without scanning a table whose prune stalled.
 */
trait BoundsRawEventLookback
{
    public const MAX_LOOKBACK_HOURS = 720;

    /**
     * @return array{hours: list<string>}
     */
    protected function lookbackRules(): array
    {
        return [
            'hours' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LOOKBACK_HOURS],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{hours: int, since: CarbonImmutable}
     */
    protected function lookback(array $validated): array
    {
        $hours = isset($validated['hours'])
            ? (int) $validated['hours']
            : max(1, min(self::MAX_LOOKBACK_HOURS, (int) config('hone-server.retention.raw_hours', 72)));

        return [
            'hours' => $hours,
            'since' => CarbonImmutable::now('UTC')->subHours($hours),
        ];
    }

    protected function lookbackSchema(JsonSchema $schema): Type
    {
        return $schema->integer()->description('Lookback window in hours over raw events (1-720). Defaults to the raw retention window.');
    }
}

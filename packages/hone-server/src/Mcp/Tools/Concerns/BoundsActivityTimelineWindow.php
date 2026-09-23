<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Tools\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Validation\ValidationException;

trait BoundsActivityTimelineWindow
{
    public const MAX_WINDOW_DAYS = 31;

    /**
     * @return array<string, list<string>>
     */
    protected function activityWindowRules(): array
    {
        $minuteTimestamp = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|[+-]\d{2}:\d{2})$/';

        return [
            'app' => ['required', 'string', 'max:255'],
            'from' => ['required', 'string', 'date', $minuteTimestamp],
            'to' => ['required', 'string', 'date', $minuteTimestamp, 'after_or_equal:from'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{from: CarbonImmutable, to: CarbonImmutable, minutes: int}
     */
    protected function activityWindow(array $validated): array
    {
        $from = CarbonImmutable::parse((string) $validated['from'])->utc();
        $to = CarbonImmutable::parse((string) $validated['to'])->utc();

        if ($to->greaterThan($from->addDays(self::MAX_WINDOW_DAYS))) {
            throw ValidationException::withMessages([
                'to' => sprintf('The activity timeline window may not exceed %d days.', self::MAX_WINDOW_DAYS),
            ]);
        }

        return [
            'from' => $from,
            'to' => $to,
            'minutes' => (int) $from->diffInMinutes($to) + 1,
        ];
    }

    protected function activityTimestampSchema(JsonSchema $schema, string $boundary): StringType
    {
        return $schema->string()
            ->description(sprintf(
                'Required minute-aligned RFC 3339 %s timestamp. Activity buckets at both from and to are included; the maximum window is %d days.',
                $boundary,
                self::MAX_WINDOW_DAYS,
            ))
            ->required();
    }
}

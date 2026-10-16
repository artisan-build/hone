<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer;

final class Normalizer
{
    /**
     * Every normalized_key column (raw_events, aggregates, samples,
     * hourly_aggregate_rollups) is varchar(255), and two of them sit in
     * unique indexes, so a key must never exceed this.
     */
    private const MAX_KEY_CHARACTERS = 255;

    /**
     * @param  array<string, mixed>  $record
     */
    public static function keyFor(string $recordType, array $record): string
    {
        $recordType = self::fallback($recordType, 'unknown');

        $key = match ($recordType) {
            'request' => trim(self::string($record['method'] ?? '').' '.self::string($record['route'] ?? $record['route_path'] ?? $record['uri'] ?? '')),
            'query' => self::string($record['sql'] ?? $recordType),
            'queued-job', 'job-attempt', 'command', 'scheduled-task' => self::string($record['name'] ?? $recordType),
            'exception' => trim(self::string($record['class'] ?? '').' '.self::string($record['file'] ?? '').(isset($record['line']) ? ':'.self::string($record['line']) : '')),
            'outgoing-request' => trim(self::string($record['method'] ?? '').' '.self::string($record['host'] ?? $record['url'] ?? '')),
            'log' => self::string($record['level'] ?? $recordType),
            'user' => self::string($record['id'] ?? $recordType),
            'cache-event' => trim(self::string($record['store'] ?? '').':'.self::string($record['type'] ?? '')),
            default => $recordType,
        };

        return self::bounded(self::fallback($key, $recordType));
    }

    /**
     * Keys at or under the column limit pass through byte-identical, so
     * existing aggregates keep grouping with new data. Longer keys become a
     * readable prefix plus a hash of the full key, so equal inputs still
     * produce equal keys and two different long keys never share one.
     */
    private static function bounded(string $key): string
    {
        if (mb_strlen($key) <= self::MAX_KEY_CHARACTERS) {
            return $key;
        }

        $hash = hash('sha256', $key);

        return mb_substr($key, 0, self::MAX_KEY_CHARACTERS - strlen($hash) - 1).'#'.$hash;
    }

    private static function string(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    private static function fallback(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value !== '') {
            return $value;
        }

        $fallback = trim($fallback);

        return $fallback !== '' ? $fallback : 'unknown';
    }
}

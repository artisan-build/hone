<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer;

final class Normalizer
{
    /**
     * @param  array<string, mixed>  $record
     */
    public static function keyFor(string $recordType, array $record): string
    {
        $recordType = self::fallback($recordType, 'unknown');

        $key = match ($recordType) {
            'request' => trim(self::string($record['method'] ?? '').' '.self::string($record['route'] ?? $record['uri'] ?? '')),
            'query' => self::string($record['sql'] ?? $recordType),
            'queued-job', 'command', 'scheduled-task' => self::string($record['name'] ?? $recordType),
            'exception' => trim(self::string($record['class'] ?? '').' '.self::string($record['file'] ?? '').(isset($record['line']) ? ':'.self::string($record['line']) : '')),
            'outgoing-request' => trim(self::string($record['method'] ?? '').' '.self::string($record['host'] ?? $record['url'] ?? '')),
            'log' => self::string($record['level'] ?? $recordType),
            'user' => self::string($record['id'] ?? $recordType),
            'cache-event' => trim(self::string($record['store'] ?? '').':'.self::string($record['type'] ?? '')),
            default => $recordType,
        };

        return self::fallback($key, $recordType);
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

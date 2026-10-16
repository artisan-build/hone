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

    private const HASH_ALGORITHM = 'sha256';

    private const HASH_CHARACTERS = 64;

    private const PREFIX_CHARACTERS = self::MAX_KEY_CHARACTERS - self::HASH_CHARACTERS - 1;

    /**
     * Exactly the canonical hashed form and nothing else: a 190-character
     * prefix, '#', and 64 lowercase hex digits — 255 characters in total.
     */
    private const HASHED_FORM_PATTERN = '/^.{'.self::PREFIX_CHARACTERS.'}#[0-9a-f]{'.self::HASH_CHARACTERS.'}$/su';

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

        return self::canonicalize(self::fallback($key, $recordType));
    }

    /**
     * The canonical stored form of a key. Keys at or under the column limit
     * pass through byte-identical — with one reserved-namespace exception —
     * so existing aggregates keep grouping with new data. Longer keys become
     * a readable prefix plus a hash of the full key, so equal inputs still
     * produce equal keys and two different long keys never share one.
     *
     * The exception keeps the two output sets disjoint: every hashed output
     * matches HASHED_FORM_PATTERN and no unchanged output does. A short key
     * that itself matches the pattern (which no real telemetry key does) is
     * re-keyed through the same hash instead of passing through, where it
     * would collide with the hashed form of some long key.
     */
    public static function canonicalize(string $key): string
    {
        if (mb_strlen($key) <= self::MAX_KEY_CHARACTERS && ! self::isHashedForm($key)) {
            return $key;
        }

        return mb_substr($key, 0, self::PREFIX_CHARACTERS).'#'.hash(self::HASH_ALGORITHM, $key);
    }

    /**
     * Resolve a caller-supplied key for lookups. A key already in the hashed
     * form came from a listing tool and is matched as-is; anything else is an
     * original normalized form and maps to its stored canonical form.
     */
    public static function lookupKey(string $key): string
    {
        return self::isHashedForm($key) ? $key : self::canonicalize($key);
    }

    private static function isHashedForm(string $key): bool
    {
        return preg_match(self::HASHED_FORM_PATTERN, $key) === 1;
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

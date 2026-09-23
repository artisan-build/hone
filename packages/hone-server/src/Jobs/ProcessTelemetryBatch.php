<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Jobs;

use ArtisanBuild\BuiltForCloud\Contracts\SystemAuthorityQueueEntry;
use ArtisanBuild\HoneServer\Contracts\AsnLookup;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Normalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessTelemetryBatch implements ShouldQueue, SystemAuthorityQueueEntry
{
    public ?string $connection = null;

    /**
     * @param  array<int, mixed>  $records
     */
    public function __construct(
        public readonly string $app,
        public readonly ?string $deploy,
        public readonly string $sentAt,
        public readonly array $records,
    ) {}

    public function handle(AsnLookup $asnLookup): void
    {
        foreach ($this->records as $record) {
            if (! is_array($record)) {
                continue;
            }

            /** @var array<string, mixed> $record */
            $recordType = $this->recordType($record);
            $enrichment = $this->enrichment($recordType, $record, $asnLookup);

            try {
                RawEvent::query()->create([
                    'app' => $this->app,
                    'record_type' => $recordType,
                    'deploy' => blank($this->deploy) ? null : $this->deploy,
                    'occurred_at' => $this->occurredAt($record),
                    'normalized_key' => Normalizer::keyFor($recordType, $record),
                    'payload' => $record,
                    ...$enrichment,
                ]);
            } catch (Throwable $e) {
                Log::warning('Failed to persist Hone telemetry record.', [
                    'app' => $this->app,
                    'record_type' => $recordType,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function recordType(array $record): string
    {
        $recordType = $record['t'] ?? 'unknown';

        if (is_scalar($recordType)) {
            $recordType = trim((string) $recordType);
        } else {
            $recordType = '';
        }

        return $recordType !== '' ? $recordType : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function occurredAt(array $record): Carbon
    {
        foreach (['timestamp', 'ts', 'occurred_at', 'time'] as $key) {
            if (array_key_exists($key, $record)) {
                $parsed = $this->parseTimestamp($record[$key]);

                if ($parsed instanceof Carbon) {
                    return $parsed;
                }
            }
        }

        return $this->parseTimestamp($this->sentAt) ?? now();
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (! is_scalar($value) || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                $timestamp = (float) $value;
                $seconds = $timestamp > 9999999999 ? $timestamp / 1000 : $timestamp;

                return Carbon::createFromTimestamp($seconds, 'UTC');
            }

            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{actor: string, ran_queries: bool|null, response: array<string, mixed>|null, user_agent: string|null, client_ip: string|null, asn: int|null}|array{}
     */
    private function enrichment(string $recordType, array $record, AsnLookup $asnLookup): array
    {
        $executionType = str_replace('_', '-', strtolower($recordType));
        $actor = match ($executionType) {
            'request', 'http-request' => $this->hasAuthenticatedUser($record) ? 'human' : 'guest',
            'scheduled-task', 'scheduled' => 'scheduled',
            'queued-job', 'job' => 'job',
            'command', 'artisan-command' => 'command',
            default => null,
        };

        if ($actor === null) {
            return [];
        }

        $isRequest = in_array($executionType, ['request', 'http-request'], true);
        $headers = $isRequest ? $this->decodedMap($record, ['headers', 'request_headers', 'requestHeaders']) : [];
        $clientIp = $isRequest ? $this->clientIp($record, $headers) : null;

        return [
            'actor' => $actor,
            'ran_queries' => $this->booleanValue($record, ['ran_queries', 'ranQueries', 'has_queries', 'hasQueries']),
            'response' => $isRequest ? $this->responseContext($record) : null,
            'user_agent' => $actor === 'guest' ? $this->userAgent($record, $headers) : null,
            'client_ip' => $clientIp,
            'asn' => $asnLookup->lookup($clientIp),
        ];
    }

    /** @param array<string, mixed> $record */
    private function hasAuthenticatedUser(array $record): bool
    {
        foreach (['user_id', 'userId', 'authenticated_user_id', 'authenticatedUserId'] as $key) {
            if (array_key_exists($key, $record) && ! blank($record[$key])) {
                return true;
            }
        }

        $user = $record['user'] ?? null;

        if (is_array($user)) {
            return ! blank($user['id'] ?? null);
        }

        return is_scalar($user) && ! blank($user);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $keys
     */
    private function booleanValue(array $record, array $keys): ?bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $record)) {
                continue;
            }

            $value = $record[$key];

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return $value > 0;
            }

            if (is_string($value)) {
                return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            }

            return null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function decodedMap(array $record, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;

            if (is_string($value)) {
                $value = json_decode($value, true);
            }

            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>|null
     */
    private function responseContext(array $record): ?array
    {
        $context = $this->decodedMap($record, ['context', 'ctx']);
        $response = $context['hone.response'] ?? data_get($context, 'hone.response');

        if (is_string($response)) {
            $response = json_decode($response, true);
        }

        return is_array($response) ? $response : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $headers
     */
    private function clientIp(array $record, array $headers): ?string
    {
        $cloudflareIp = $this->header($headers, 'cf-connecting-ip');
        $value = $cloudflareIp ?? $this->firstScalar($record, ['ip', 'remote_ip', 'remoteIp', 'client_ip', 'clientIp']);

        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $headers
     */
    private function userAgent(array $record, array $headers): ?string
    {
        return $this->firstScalar($record, ['user_agent', 'userAgent']) ?? $this->header($headers, 'user-agent');
    }

    /** @param array<string, mixed> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (! is_string($key) || strtolower($key) !== $name) {
                continue;
            }

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_scalar($value) ? trim((string) $value) : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $keys
     */
    private function firstScalar(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $record[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}

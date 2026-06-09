<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Jobs;

use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Normalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessTelemetryBatch implements ShouldQueue
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

    public function handle(): void
    {
        foreach ($this->records as $record) {
            if (! is_array($record)) {
                continue;
            }

            /** @var array<string, mixed> $record */
            $recordType = $this->recordType($record);

            try {
                RawEvent::query()->create([
                    'app' => $this->app,
                    'record_type' => $recordType,
                    'deploy' => blank($this->deploy) ? null : $this->deploy,
                    'occurred_at' => $this->occurredAt($record),
                    'normalized_key' => Normalizer::keyFor($recordType, $record),
                    'payload' => $record,
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

        if (is_scalar($recordType) || $recordType === null) {
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
}

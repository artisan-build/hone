<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneContracts;

use ArtisanBuild\HoneContracts\Exceptions\InvalidEnvelope;

/**
 * The thin, versioned wire envelope that crosses the network between a monitored
 * app (hone-client) and a Hone server (hone-server).
 *
 * The envelope wraps a batch of opaque Nightwatch records with the minimum metadata
 * the server needs to route, tag, and version-check them. Only the envelope itself is
 * version-sensitive — the inner records are never interpreted here.
 *
 * Compatibility rule: the envelope evolves ADDITIVELY within a major. New fields are
 * optional and added; existing fields are never removed or repurposed. {@see fromArray()}
 * therefore tolerates unknown extra keys (a newer sender talking to an older server) and
 * supplies defaults for absent optional keys (an older sender talking to a newer server).
 */
final class Envelope
{
    /**
     * The envelope major version this build speaks. Bump ONLY on a breaking wire
     * change. Crossing this in a client is a deliberate `composer require`, never a
     * routine `composer update` (hone-client pins hone-contracts at `^X`).
     */
    public const int VERSION = 1;

    /**
     * @param  string  $app  The source application id (one Hone env may receive many apps).
     * @param  string|null  $deploy  The deploy identifier — NIGHTWATCH_DEPLOY, typically the commit SHA.
     * @param  string  $sentAt  ISO-8601 timestamp of when the batch was assembled.
     * @param  list<array<string, mixed>>  $records  Opaque Nightwatch records; each carries its own `t` discriminator.
     * @param  int  $envelopeVersion  The envelope major the sender was built against.
     */
    public function __construct(
        public readonly string $app,
        public readonly ?string $deploy,
        public readonly string $sentAt,
        public readonly array $records,
        public readonly int $envelopeVersion = self::VERSION,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public static function make(string $app, ?string $deploy, string $sentAt, array $records): self
    {
        return new self($app, $deploy, $sentAt, array_values($records));
    }

    /**
     * @return array{envelope_version: int, app: string, deploy: string|null, sent_at: string, records: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'app' => $this->app,
            'deploy' => $this->deploy,
            'sent_at' => $this->sentAt,
            'records' => array_values($this->records),
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * Reconstruct an envelope from its array form. Tolerant by design: unknown keys are
     * ignored and absent optional keys take defaults — that tolerance is what lets a
     * backward-compatible server parse every older major.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidEnvelope When required fields are missing or malformed.
     */
    public static function fromArray(array $data): self
    {
        $version = self::versionFrom($data);

        if (! isset($data['app']) || ! is_string($data['app']) || $data['app'] === '') {
            throw new InvalidEnvelope('Envelope is missing a non-empty string "app".');
        }

        $records = $data['records'] ?? [];

        if (! is_array($records)) {
            throw new InvalidEnvelope('Envelope "records" must be an array.');
        }

        $deploy = $data['deploy'] ?? null;

        return new self(
            app: $data['app'],
            deploy: $deploy === null ? null : (string) $deploy,
            sentAt: isset($data['sent_at']) ? (string) $data['sent_at'] : '',
            records: array_values($records),
            envelopeVersion: $version,
        );
    }

    /**
     * @throws InvalidEnvelope
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidEnvelope('Envelope is not valid JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_array($data)) {
            throw new InvalidEnvelope('Envelope JSON must decode to an object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    /**
     * Peek at an envelope's major version without fully parsing it. The ingest endpoint
     * uses this to reject envelopes newer than it understands with a loud 4xx before it
     * attempts to interpret anything else.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidEnvelope
     */
    public static function versionFrom(array $data): int
    {
        if (! isset($data['envelope_version']) || ! is_numeric($data['envelope_version'])) {
            throw new InvalidEnvelope('Envelope is missing a numeric "envelope_version".');
        }

        return (int) $data['envelope_version'];
    }
}

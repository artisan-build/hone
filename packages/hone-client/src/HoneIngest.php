<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Laravel\Nightwatch\Contracts\Ingest;
use Psr\Log\LoggerInterface;
use Throwable;

final class HoneIngest implements Ingest
{
    private const MINIMUM_TIMEOUT = 0.05;

    /**
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private ?Carbon $oldestBufferedAt = null;

    private bool $shouldDigestWhenBufferIsFull = true;

    private int $overflowDroppedRecords = 0;

    private int $failedDeliveryRecords = 0;

    private bool $overflowWarningLogged = false;

    private readonly float $connectTimeout;

    private readonly float $timeout;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $app,
        private readonly ?string $deploy,
        private readonly int $bufferLimit,
        private readonly float $flushInterval,
        private readonly bool $runningInConsole,
        float $connectTimeout,
        float $timeout,
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
    ) {
        $this->connectTimeout = max(self::MINIMUM_TIMEOUT, $connectTimeout);
        $this->timeout = max(self::MINIMUM_TIMEOUT, $timeout);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        $this->oldestBufferedAt ??= Carbon::now();
        $this->buffer[] = $record;

        if ($this->runningInConsole
            && (($this->shouldDigestWhenBufferIsFull && count($this->buffer) >= max(1, $this->bufferLimit))
                || $this->flushIntervalElapsed())) {
            $this->digest();

            return;
        }

        while (count($this->buffer) > max(0, $this->bufferLimit)) {
            array_shift($this->buffer);
            $this->overflowDroppedRecords++;
            $this->warnAboutOverflow();
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function writeNow(array $record): void
    {
        $this->send([$record], clearBuffer: false);
    }

    public function ping(): void
    {
        // No socket keepalive is needed for Hone's HTTP transport.
    }

    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        $this->send($this->buffer, clearBuffer: true);
    }

    public function flush(): void
    {
        $this->buffer = [];
        $this->oldestBufferedAt = null;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function send(array $records, bool $clearBuffer): void
    {
        if ($records === []) {
            return;
        }

        try {
            $envelope = Envelope::make(
                app: $this->app,
                deploy: $this->deploy,
                sentAt: Carbon::now()->toIso8601String(),
                records: $records,
                overflowDroppedRecords: $this->overflowDroppedRecords,
                failedDeliveryRecords: $this->failedDeliveryRecords,
            )->toArray();

            $this->pendingRequest()
                ->withToken($this->token)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->url, $envelope)
                ->throw();

            $this->overflowDroppedRecords = 0;
            $this->failedDeliveryRecords = 0;
        } catch (Throwable $e) {
            $this->failedDeliveryRecords += count($records);
            $this->debug('Hone ingest failed; dropping buffered records.', $e);
        } finally {
            if ($clearBuffer) {
                $this->buffer = [];
                $this->oldestBufferedAt = null;
            }
        }
    }

    private function flushIntervalElapsed(): bool
    {
        return $this->oldestBufferedAt !== null
            && $this->flushInterval >= 0
            && $this->oldestBufferedAt->copy()->addSeconds($this->flushInterval)->lte(Carbon::now());
    }

    private function warnAboutOverflow(): void
    {
        if ($this->overflowWarningLogged) {
            return;
        }

        $this->overflowWarningLogged = true;

        try {
            $this->logger->warning('Hone ingest buffer overflowed; dropping the oldest telemetry records.');
        } catch (Throwable) {
            // Fail open even if the host application's logger is unavailable.
        }
    }

    /**
     * Start the request to the Hone server carrying this installation's BfC
     * client identity, so the server can attribute the ingest token to a
     * specific install.
     *
     * The identity only labels the install and never grants anything, so an
     * identity that cannot be resolved degrades to an unlabelled request
     * rather than stopping telemetry from reaching Hone.
     */
    private function pendingRequest(): PendingRequest
    {
        try {
            return $this->http->withClientIdentity();
        } catch (Throwable $e) {
            $this->debug('Hone ingest could not resolve the BfC client identity; sending without it.', $e);

            return $this->http->createPendingRequest();
        }
    }

    private function debug(string $message, Throwable $e): void
    {
        try {
            $this->logger->debug($message, ['exception' => $e]);
        } catch (Throwable) {
            // Fail open even if the host application's logger is unavailable.
        }
    }
}

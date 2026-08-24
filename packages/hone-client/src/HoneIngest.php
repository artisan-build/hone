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

    private bool $shouldDigestWhenBufferIsFull = true;

    private readonly float $connectTimeout;

    private readonly float $timeout;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $app,
        private readonly ?string $deploy,
        private readonly int $bufferLimit,
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
        $this->buffer[] = $record;

        // Hone intentionally drops on overflow instead of posting mid-request.
        while (count($this->buffer) > max(0, $this->bufferLimit)) {
            array_shift($this->buffer);
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
        // Stored for contract compatibility; Hone never digests mid-request on full buffers.
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        $this->send($this->buffer, clearBuffer: true);
    }

    public function flush(): void
    {
        $this->buffer = [];
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
            )->toArray();

            $this->pendingRequest()
                ->withToken($this->token)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->url, $envelope)
                ->throw();
        } catch (Throwable $e) {
            $this->debug('Hone ingest failed; dropping buffered records.', $e);
        } finally {
            if ($clearBuffer) {
                $this->buffer = [];
            }
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

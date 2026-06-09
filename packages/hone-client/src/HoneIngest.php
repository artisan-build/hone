<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Laravel\Nightwatch\Contracts\Ingest;
use Psr\Log\LoggerInterface;
use Throwable;

final class HoneIngest implements Ingest
{
    private const float MINIMUM_TIMEOUT = 0.05;

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

            $this->http
                ->withToken($this->token)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->url, $envelope)
                ->throw();
        } catch (Throwable $e) {
            try {
                $this->logger->debug('Hone ingest failed; dropping buffered records.', [
                    'exception' => $e,
                ]);
            } catch (Throwable) {
                // Fail open even if the host application's logger is unavailable.
            }
        } finally {
            if ($clearBuffer) {
                $this->buffer = [];
            }
        }
    }
}

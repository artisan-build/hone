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
    /**
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private bool $shouldDigestWhenBufferIsFull = true;

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $app,
        private readonly ?string $deploy,
        private readonly int $bufferLimit,
        private readonly float $connectTimeout,
        private readonly float $timeout,
        private readonly Factory $http,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        $this->buffer[] = $record;

        while (count($this->buffer) > max(0, $this->bufferLimit)) {
            array_shift($this->buffer);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function writeNow(array $record): void
    {
        $this->write($record);
        $this->send();
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
        $this->send();
    }

    public function flush(): void
    {
        $this->send();
    }

    private function send(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $records = $this->buffer;

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
            $this->logger->debug('Hone ingest failed; dropping buffered records.', [
                'exception' => $e,
            ]);
        } finally {
            $this->buffer = [];
        }
    }
}

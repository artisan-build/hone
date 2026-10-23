<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Laravel\Nightwatch\Contracts\Ingest;
use Laravel\Nightwatch\Core;
use Psr\Log\LoggerInterface;
use Throwable;

final class HoneIngest implements Ingest
{
    private const MINIMUM_TIMEOUT = 0.05;

    /**
     * How long a loss count may wait in the cache for a later request to carry
     * it to Hone. Long enough to survive a slow patch of ingest, short enough
     * that a count never shows up attached to an unrelated incident.
     */
    private const LOSS_CARRY_SECONDS = 3600;

    /**
     * The cURL phase timings worth reporting, mapped from the curl_getinfo
     * field that carries each one. Every value is cumulative from the start of
     * the transfer, which is how cURL reports them.
     *
     * @var array<string, string>
     */
    private const FAILURE_TIMING_FIELDS = [
        'namelookup_ms' => 'namelookup_time',
        'connect_ms' => 'connect_time',
        'tls_ms' => 'appconnect_time',
        'total_ms' => 'total_time',
    ];

    /**
     * @var list<array<string, mixed>>
     */
    private array $buffer = [];

    private ?Carbon $oldestBufferedAt = null;

    private bool $shouldDigestWhenBufferIsFull = true;

    private int $overflowDroppedRecords = 0;

    private int $failedDeliveryRecords = 0;

    private bool $overflowWarningLogged = false;

    private bool $sending = false;

    /**
     * The connect budget, never above the total budget: cURL measures both from
     * the same instant, so a connect timeout larger than the total one can
     * never be reached and only misleads whoever reads the configuration.
     */
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
        private readonly Core $nightwatch,
        private readonly ?CacheRepository $cache = null,
        private readonly ?IngestDiscovery $discovery = null,
    ) {
        $this->timeout = max(self::MINIMUM_TIMEOUT, $timeout);
        $this->connectTimeout = min($this->timeout, max(self::MINIMUM_TIMEOUT, $connectTimeout));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): void
    {
        $this->oldestBufferedAt ??= Carbon::now();
        $this->buffer[] = $record;

        if ($this->sending) {
            return;
        }

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
        if ($records === [] || $this->sending) {
            return;
        }

        $this->sending = true;

        try {
            $this->nightwatch->ignore(function () use ($records, $clearBuffer): void {
                if ($clearBuffer) {
                    $this->buffer = [];
                    $this->oldestBufferedAt = null;
                }

                $carriedFailedRecords = $this->carriedFailedDeliveryRecords();

                try {
                    $destination = $this->discovery?->destination() ?? $this->url;

                    $envelope = Envelope::make(
                        app: $this->app,
                        deploy: $this->deploy,
                        sentAt: Carbon::now()->toIso8601String(),
                        records: $records,
                        overflowDroppedRecords: $this->overflowDroppedRecords,
                        failedDeliveryRecords: $this->failedDeliveryRecords + $carriedFailedRecords,
                    )->toArray();

                    $this->pendingRequest()
                        ->withToken($this->token)
                        ->connectTimeout($this->connectTimeout)
                        ->timeout($this->timeout)
                        ->post($destination, $envelope)
                        ->throw();

                    $this->overflowDroppedRecords = 0;
                    $this->failedDeliveryRecords = 0;
                    $this->forgetCarriedFailedDeliveryRecords($carriedFailedRecords);
                } catch (Throwable $e) {
                    $failedRecords = count($records);

                    $this->warnAboutFailedDelivery($failedRecords, $e);

                    if (! $this->carryFailedDeliveryRecords($carriedFailedRecords + $failedRecords)) {
                        $this->failedDeliveryRecords += $carriedFailedRecords + $failedRecords;
                    }
                }
            });
        } finally {
            $this->sending = false;
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
            $this->nightwatch->ignore(
                fn () => $this->logger->warning('Hone ingest buffer overflowed; dropping the oldest telemetry records.')
            );
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

    /**
     * A dropped batch is the one failure mode nobody can see from the Hone side,
     * so it is reported at warning level with the number of records lost. The
     * host application's log stream is the only channel still open once the
     * final attempt has failed.
     */
    private function warnAboutFailedDelivery(int $records, Throwable $e): void
    {
        $timings = $this->failureTimings($e);

        try {
            $this->logger->warning(
                "Hone ingest delivery failed; dropped {$records} telemetry records.",
                ['dropped_records' => $records, ...$timings, 'exception' => $e],
            );
        } catch (Throwable) {
            // Fail open even if the host application's logger is unavailable.
        }
    }

    /**
     * Where the time went before the delivery failed, in whole cumulative
     * milliseconds.
     *
     * Whoever reads a dropped batch needs to know whether the budget went on
     * resolving the host, opening the socket or the TLS handshake, and a cURL
     * failure already carries that breakdown. Nothing is measured here and
     * nothing is asked of the transport, so a successful delivery does no work
     * at all.
     *
     * A phase the transfer never reached is reported as cURL reports it, which
     * is zero; only a field the context does not carry is left out.
     *
     * @return array<string, int>
     */
    private function failureTimings(Throwable $e): array
    {
        $context = $this->handlerContext($e);

        $timings = [];

        foreach (self::FAILURE_TIMING_FIELDS as $key => $field) {
            $seconds = $context[$field] ?? null;

            if (is_int($seconds) || is_float($seconds)) {
                $timings[$key] = (int) round($seconds * 1000);
            }
        }

        return $timings;
    }

    /**
     * The handler context carried by the first exception in the chain that
     * exposes one.
     *
     * Laravel's HTTP client wraps a transport failure in its own
     * ConnectionException rather than replacing it, so the exception holding
     * the context is the wrapper's previous exception. The context is read by
     * capability rather than by class because whether an exception carries one
     * at all depends on the Guzzle major the host application resolved: Guzzle
     * 7 attaches the curl_getinfo fields to its transfer exceptions, and
     * Guzzle 8 exposes them only through an on_stats callback, which this
     * client does not install because it would run on every successful
     * request. On a host where nothing carries a context, a failed delivery
     * logs the warning without timings.
     *
     * @return array<string, mixed>
     */
    private function handlerContext(Throwable $e): array
    {
        for ($candidate = $e; $candidate !== null; $candidate = $candidate->getPrevious()) {
            $readContext = [$candidate, 'getHandlerContext'];

            if (! is_callable($readContext)) {
                continue;
            }

            try {
                $context = $readContext();
            } catch (Throwable) {
                return [];
            }

            return is_array($context) ? $context : [];
        }

        return [];
    }

    /**
     * Loss counts recorded by requests that have already ended.
     *
     * A web request dies on the terminate path, so the in-memory counter it
     * increments never reaches Hone. The host application's cache carries the
     * count to whichever later request manages a successful post. It is a
     * best-effort diagnostic: concurrent posts can report the same count twice
     * or drop it, and an application without a usable cache falls back to the
     * in-memory counter.
     */
    private function carriedFailedDeliveryRecords(): int
    {
        if ($this->cache === null) {
            return 0;
        }

        try {
            return max(0, (int) $this->cache->get($this->lossCacheKey(), 0));
        } catch (Throwable) {
            return 0;
        }
    }

    private function carryFailedDeliveryRecords(int $records): bool
    {
        if ($this->cache === null || $records <= 0) {
            return false;
        }

        try {
            return $this->cache->put($this->lossCacheKey(), $records, self::LOSS_CARRY_SECONDS) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function forgetCarriedFailedDeliveryRecords(int $records): void
    {
        if ($this->cache === null || $records <= 0) {
            return;
        }

        try {
            $this->cache->forget($this->lossCacheKey());
        } catch (Throwable) {
            // Fail open even if the host application's cache is unavailable.
        }
    }

    private function lossCacheKey(): string
    {
        return 'hone-client:failed-delivery-records:'.sha1($this->app);
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

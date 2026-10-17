<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Assert;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class LiveIngestHarness
{
    /** @var array<string, string> */
    private array $schemas = [];

    /** @var array<string, Process> */
    private array $processes = [];

    /** @var array<string, string> */
    private array $urls = [];

    private ?PDO $database = null;

    private string $secret;

    public static function cargo(): ?string
    {
        return (new ExecutableFinder)->find('cargo');
    }

    public function start(): void
    {
        $root = dirname(__DIR__, 4);
        $build = new Process([self::cargo(), 'build', '--locked', '--manifest-path', $root.'/ingest/Cargo.toml', '--target-dir', $root.'/ingest/target', '--bin', 'hone-ingest'], $root, timeout: 300);
        $build->disableOutput();

        if ($build->run() !== 0) {
            throw new RuntimeException('Parity Cargo build failed; run cargo build --locked --manifest-path ingest/Cargo.toml for compiler diagnostics.');
        }

        try {
            $this->database = new PDO('pgsql:host=127.0.0.1;port=5432;dbname=hone_server_test;connect_timeout=5', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->database->exec("SET statement_timeout = '10s'");
            $this->database->exec("SET timezone = 'UTC'");
        } catch (Throwable) {
            throw new RuntimeException('Live parity requires local PostgreSQL on port 5432, database hone_server_test, and the package test role. No database setup is skipped.');
        }

        $prefix = 'hone_parity_'.bin2hex(random_bytes(12));
        $this->secret = bin2hex(random_bytes(32));
        $credentialId = (string) Str::uuid();

        foreach (['php', 'rust'] as $implementation) {
            $schema = $prefix.'_'.$implementation;
            $this->database->exec('CREATE SCHEMA '.$schema);
            $this->schemas[$implementation] = $schema;
            $migration = new Process([PHP_BINARY, __DIR__.'/../Fixtures/parity-http.php'], dirname(__DIR__, 2), ['HONE_PARITY_SCHEMA' => $schema, 'APP_ENV' => 'testing'], timeout: 60);
            $migration->disableOutput();

            if ($migration->run() !== 0) {
                throw new RuntimeException('Parity '.$implementation.' migrations failed; check package Testbench configuration and local PostgreSQL permissions.');
            }

            $statement = $this->database->prepare('INSERT INTO '.$schema.'.credentials (id, kind, purpose, subject_type, subject_ref, secret_hash, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())');
            $statement->execute([$credentialId, 'bearer', 'consumption', 'installation', 'checkout', hash('sha256', $this->secret), 'active']);

            $port = $this->availablePort();
            $this->urls[$implementation] = 'http://127.0.0.1:'.$port;
            $process = $implementation === 'php'
                ? new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/../Fixtures/parity-http.php'], dirname(__DIR__, 2), ['HONE_PARITY_SCHEMA' => $schema, 'APP_ENV' => 'testing'], timeout: 180)
                : new Process([$root.'/ingest/target/debug/hone-ingest'], $root, [
                    'DATABASE_URL' => 'postgres://root@127.0.0.1:5432/hone_server_test?options='.rawurlencode('-c search_path='.$schema),
                    'PORT' => (string) $port,
                    'HONE_IPTOASN_PATH' => __DIR__.'/../Fixtures/ip2asn.tsv',
                    'HONE_RUST_CRED_TTL_SECONDS' => '0',
                    'APP_ENV' => 'testing',
                ], timeout: 180);
            $process->disableOutput();
            $this->processes[$implementation] = $process;
            $process->start();
            $this->waitUntilReady($implementation, $implementation === 'rust' ? '/up' : '/capabilities');
        }
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) {
            $process->stop(2);
            Assert::assertFalse($process->isRunning(), 'Parity child process remained alive after teardown.');
        }

        foreach ($this->schemas as $schema) {
            $this->database?->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            $statement = $this->database->prepare('SELECT count(*) FROM pg_namespace WHERE nspname = ?');
            $statement->execute([$schema]);
            Assert::assertSame(0, (int) $statement->fetchColumn(), 'Parity schema remained after teardown.');
        }

        $this->schemas = [];
        $this->database = null;
    }

    public function assertAuthentication(): void
    {
        foreach (['php', 'rust'] as $implementation) {
            foreach ([null, 'not-a-valid-parity-credential'] as $secret) {
                $this->assertStatus($implementation, ReceivePathCorpus::envelope([]), 401, $secret);
            }
            Assert::assertSame(0, $this->count($implementation, 'raw_events'));
            Assert::assertSame(0, $this->count($implementation, 'ingest_counters'));
        }
    }

    /** @param array{envelopes: list<array<string, mixed>>, rows: int, counters: int, reject_queries?: bool} $case */
    public function assertCase(string $name, array $case): void
    {
        $startedAt = time();
        foreach ($this->schemas as $implementation => $schema) {
            $this->database->exec('TRUNCATE '.$schema.'.raw_events, '.$schema.'.ingest_counters RESTART IDENTITY');
            if ($case['reject_queries'] ?? false) {
                $this->database->exec('ALTER TABLE '.$schema.".raw_events ADD CONSTRAINT parity_reject_query CHECK (record_type <> 'query')");
            }

            try {
                foreach ($case['envelopes'] as $envelope) {
                    $this->assertStatus($implementation, $envelope, 202, $this->secret);
                }

                $deadline = microtime(true) + 5;
                do {
                    $rows = $this->count($implementation, 'raw_events');
                    $counters = $this->count($implementation, 'ingest_counters');
                    if ($rows === $case['rows'] && $counters === $case['counters']) {
                        break;
                    }
                    usleep(20_000);
                } while (microtime(true) < $deadline);

                Assert::assertSame($case['rows'], $rows, $name.' / '.$implementation.' / raw_events row count');
                Assert::assertSame($case['counters'], $counters, $name.' / '.$implementation.' / ingest_counters row count');
                foreach (['raw_events', 'ingest_counters'] as $table) {
                    $misattributed = $this->database->query('SELECT count(*) FROM '.$schema.'.'.$table." WHERE app <> 'checkout'")->fetchColumn();
                    Assert::assertSame(0, (int) $misattributed, $name.' / '.$implementation.' / '.$table.' must use the credential subject, not the forged envelope app');
                }
                Assert::assertSame(1, (int) $this->database->query('SELECT count(*) FROM '.$schema.'.credentials WHERE last_used_at IS NOT NULL')->fetchColumn(), $name.' / '.$implementation.' committed credential usage');
                Assert::assertSame(1, (int) $this->database->query('SELECT count(*) FROM '.$schema.".credential_audit_events WHERE event = 'first_used'")->fetchColumn(), $name.' / '.$implementation.' committed first-use audit');
                if ($name === 'additive losses and deploy uniqueness') {
                    $totals = $this->database->query('SELECT deploy, overflow_dropped_records, failed_delivery_records FROM '.$schema.'.ingest_counters ORDER BY deploy NULLS LAST')->fetchAll(PDO::FETCH_ASSOC);
                    Assert::assertTrue($totals === [
                        ['deploy' => '123', 'overflow_dropped_records' => 2, 'failed_delivery_records' => 3],
                        ['deploy' => 'abc123', 'overflow_dropped_records' => 7, 'failed_delivery_records' => 10],
                        ['deploy' => null, 'overflow_dropped_records' => 28, 'failed_delivery_records' => 32],
                    ], $name.' / '.$implementation.' additive totals differ (values withheld)');
                }
            } finally {
                if ($case['reject_queries'] ?? false) {
                    $this->database->exec('ALTER TABLE '.$schema.'.raw_events DROP CONSTRAINT parity_reject_query');
                }
            }
        }

        foreach (['raw_events', 'ingest_counters'] as $table) {
            $expected = $this->canonicalRows('php', $table, $startedAt);
            $actual = $this->canonicalRows('rust', $table, $startedAt);
            foreach ($expected as $index => $row) {
                foreach ($row as $column => $value) {
                    Assert::assertTrue($value === ($actual[$index][$column] ?? null), $name.' / '.$table.' / row '.$index.' / '.$column.' differs (values withheld)');
                }
            }
            Assert::assertTrue($expected === $actual, $name.' / '.$table.' complete canonical rows differ (values withheld)');
        }
    }

    /** @return list<array<string, mixed>> */
    private function canonicalRows(string $implementation, string $table, int $startedAt): array
    {
        $rows = $this->database->query('SELECT * FROM '.$this->schemas[$implementation].'.'.$table)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            /** Only generated IDs and receipt clocks differ; clock values must still fall inside this case's wall-clock window. */
            foreach ($table === 'ingest_counters' ? ['created_at', 'updated_at'] : ['created_at'] as $column) {
                $instant = strtotime($row[$column]);
                Assert::assertTrue($instant !== false && $instant >= $startedAt - 1 && $instant <= time() + 1, $implementation.' / '.$table.' / '.$column.' outside case receipt window (values withheld)');
            }
            unset($row['id'], $row['created_at']);
            if ($table === 'ingest_counters') {
                unset($row['updated_at']);
            }
            ksort($row);
        }
        unset($row);
        usort($rows, static fn (array $left, array $right): int => strcmp(json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR)));

        return $rows;
    }

    private function count(string $implementation, string $table): int
    {
        return (int) $this->database->query('SELECT count(*) FROM '.$this->schemas[$implementation].'.'.$table)->fetchColumn();
    }

    /** @param array<string, mixed> $envelope */
    private function assertStatus(string $implementation, array $envelope, int $expected, #[SensitiveParameter] ?string $secret): void
    {
        try {
            $this->processes[$implementation]->checkTimeout();
            $client = Http::connectTimeout(1)->timeout(5)->acceptJson();
            if ($secret !== null) {
                $client = $client->withToken($secret);
            }
            $status = $client->post($this->urls[$implementation].'/ingest', $envelope)->status();
        } catch (Throwable) {
            throw new RuntimeException('Parity '.$implementation.' HTTP request failed; response bodies and credentials withheld.');
        }
        Assert::assertSame($expected, $status, $implementation.' POST /ingest status (body withheld)');
    }

    private function waitUntilReady(string $implementation, string $path): void
    {
        $deadline = microtime(true) + 15;
        do {
            $process = $this->processes[$implementation];
            $process->checkTimeout();
            if (! $process->isRunning()) {
                throw new RuntimeException('Parity '.$implementation.' exited before readiness (exit '.$process->getExitCode().'); check executable, local PostgreSQL, and migrations. Output withheld.');
            }
            try {
                if (Http::connectTimeout(1)->timeout(1)->get($this->urls[$implementation].$path)->status() === 200) {
                    return;
                }
            } catch (ConnectionException) {
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Parity '.$implementation.' readiness timed out after 15s; check loopback binding, PHP extensions, and local PostgreSQL. Output withheld.');
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve an available parity loopback port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}

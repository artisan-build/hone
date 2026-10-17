<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Tests\Support;

use ArtisanBuild\HoneServer\Normalizer;

final class ReceivePathCorpus
{
    /** @return list<array<string, mixed>> */
    public static function basicRecords(): array
    {
        return [
            ['t' => 'query', 'sql' => 'select * from users where id = ?', 'duration' => 12_000, 'timestamp' => '2026-06-09T12:00:01+00:00'],
            ['t' => 'request', 'method' => 'GET', 'route' => '/', 'duration_ms' => 34, 'ts' => 1781006402000],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function backgroundRecords(): array
    {
        return [
            ['v' => 1, 't' => 'scheduled-task', 'name' => 'schedule:run', 'status' => 'processed', 'queries' => 0],
            ['v' => 1, 't' => 'queued-job', 'name' => 'SendWelcomeEmail', 'queries' => 0],
            ['v' => 1, 't' => 'job-attempt', 'name' => 'SendWelcomeEmail', 'status' => 'processed', 'queries' => 4],
            ['t' => 'artisan-command', 'name' => 'reports:build', 'hasQueries' => 'true'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function requestRecords(): array
    {
        return [
            [
                'v' => 1, 't' => 'request', 'method' => 'GET', 'route_path' => '/', 'user' => '', 'queries' => 1, 'ip' => '8.8.8.8',
                'headers' => json_encode([
                    'CF-Connecting-IP' => ['1.1.1.1'], 'Host' => ['checkout.example.com:443'], 'User-Agent' => ['Mozilla/5.0'],
                ], JSON_THROW_ON_ERROR),
                'context' => json_encode(['hone.response' => [
                    'status' => 200, 'content_type' => 'text/html; charset=UTF-8', 'cache_control' => 'no-cache, private',
                ]], JSON_THROW_ON_ERROR),
            ],
            [
                'v' => 1, 't' => 'request', 'method' => 'GET', 'route_path' => '/static', 'user' => '', 'queries' => 0,
                'ip' => '1.1.1.1', 'headers' => ['user-agent' => 'curl/8.0'],
            ],
            ['v' => 1, 't' => 'request', 'method' => 'GET', 'route_path' => '/dashboard', 'queries' => 2, 'user' => '42', 'ip' => '1.1.1.1'],
        ];
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function boundedKeys(): array
    {
        return [
            'request' => ['request', ['method' => 'GET', 'route' => '/'.str_repeat('segment/', 60)]],
            'query' => ['query', ['sql' => str_repeat('select 1 union ', 40)]],
            'queued job' => ['queued-job', ['name' => str_repeat('App\\Jobs\\VeryLongJobName', 20)]],
            'job attempt' => ['job-attempt', ['name' => str_repeat('App\\Jobs\\VeryLongJobName', 20)]],
            'command' => ['command', ['name' => str_repeat('reports:build:', 30)]],
            'scheduled task' => ['scheduled-task', ['name' => str_repeat('schedule:run:', 30)]],
            'exception' => ['exception', ['class' => str_repeat('App\\Exceptions\\Long', 10), 'file' => str_repeat('/var/www/app/', 20), 'line' => 42]],
            'outgoing request' => ['outgoing-request', ['method' => 'GET', 'host' => str_repeat('sub.', 80).'example.com']],
            'log' => ['log', ['level' => str_repeat('emergency', 40)]],
            'user' => ['user', ['id' => str_repeat('1234567890', 30)]],
            'cache event' => ['cache-event', ['store' => str_repeat('redis', 40), 'type' => str_repeat('hit', 40)]],
            'unknown record type' => [str_repeat('custom-type', 30), []],
            'multibyte key' => ['query', ['sql' => str_repeat('sélect Ω ', 60)]],
        ];
    }

    /** @return array<string, array{envelopes: list<array<string, mixed>>, rows: int, counters: int, reject_queries?: bool}> */
    public static function cases(): array
    {
        $case = static fn (array $records, int $rows): array => [
            'envelopes' => [self::envelope($records)], 'rows' => $rows, 'counters' => 0,
        ];
        $longSql = 'select 1 -- '.str_repeat('x', 300);
        $cases = [
            'basic persistence' => $case(self::basicRecords(), 2),
            'Nightwatch enrichment' => $case(self::requestRecords(), 3),
            'background aliases' => $case(self::backgroundRecords(), 4),
            'empty records' => $case([], 0),
            'invalid scalar and list rows' => $case([null, false, 42, 'invalid', [], ['list-shaped', 'record'], ['t' => 'mail', 'name' => 'Receipt']], 3),
            'reserved normalized keys' => $case([
                ['t' => 'query', 'sql' => $longSql],
                ['t' => 'query', 'sql' => Normalizer::keyFor('query', ['sql' => $longSql])],
                ['t' => 'query', 'sql' => 'select '.str_repeat('a', 248)],
            ], 3),
            'rejected row fallback' => [
                ...$case([
                    ['t' => 'request', 'method' => 'GET', 'route' => '/one'],
                    ['t' => 'query', 'sql' => 'select secret_value from users'],
                    ['t' => 'request', 'method' => 'GET', 'route' => '/two'],
                ], 2),
                'reject_queries' => true,
            ],
            'bounded bulk inserts' => $case(array_map(
                static fn (int $index): array => ['t' => 'query', 'sql' => 'select '.$index, 'duration_ms' => $index],
                range(1, 1201),
            ), 1201),
        ];

        foreach (self::boundedKeys() as $name => [$type, $record]) {
            $cases['bounded key '.$name] = $case([['t' => $type, ...$record]], $name === 'unknown record type' ? 0 : 1);
        }

        foreach (['not-an-ip', '10.0.0.1'] as $index => $cloudflareIp) {
            $cases['Cloudflare fallback '.$index] = $case([[
                'v' => 1, 't' => 'request', 'method' => 'GET', 'route_path' => '/', 'user' => '', 'queries' => 0,
                'ip' => '8.8.8.8', 'headers' => json_encode(['CF-Connecting-IP' => [$cloudflareIp]], JSON_THROW_ON_ERROR),
            ]], 1);
        }

        foreach ([null, ' ', false, 123, 0, true] as $index => $deploy) {
            $cases['raw event deploy coercion '.$index] = [
                ...$case([['t' => 'query', 'sql' => 'select 1']], 1),
                'envelopes' => [[...self::envelope([['t' => 'query', 'sql' => 'select 1']]), 'deploy' => $deploy]],
            ];
        }

        $cases['additive losses and deploy uniqueness'] = [
            'envelopes' => array_map(static fn (array $values): array => [
                ...self::envelope([]), 'deploy' => $values[0],
                'losses' => ['overflow_dropped_records' => $values[1], 'failed_delivery_records' => $values[2]],
            ], [['abc123', 2, 3], ['abc123', 5, 7], [null, 11, 13], [' ', 17, 19], [false, 0, 0], [123, '2', '3']]),
            'rows' => 0, 'counters' => 3,
        ];

        $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/rust-ingest-rows.json'), flags: JSON_THROW_ON_ERROR);
        $records = $fixture->records;
        if (PHP_OS_FAMILY === 'Darwin') {
            /** Darwin libc changes parse_url control-byte handling for these four declared paths only. Linux CI replays every original JSON object, including empty/numeric maps. */
            $droppedPaths = $fixture->darwin_php_control_byte_dropped_request_paths;
            $records = array_values(array_filter($records, static fn (mixed $record): bool => ! in_array($record->route_path ?? null, $droppedPaths, true)));
        }
        $cases['shared golden corpus'] = [
            'envelopes' => [[
                ...self::envelope([...$records, null, false, 42, 'scalar']),
                'deploy' => $fixture->deploy,
                'sent_at' => $fixture->sent_at,
                'losses' => ['overflow_dropped_records' => 2, 'failed_delivery_records' => 3],
            ]],
            'rows' => count($records), 'counters' => 1,
        ];

        return $cases;
    }

    /** @param list<mixed> $records
     * @return array<string, mixed>
     */
    public static function envelope(array $records): array
    {
        return [
            'envelope_version' => 1, 'app' => 'forged-app', 'deploy' => 'abc123',
            'sent_at' => '2026-06-09T12:00:00+00:00', 'records' => $records,
        ];
    }
}

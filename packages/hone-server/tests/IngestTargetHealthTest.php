<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\HoneServer\Maintenance\MaintenanceHealth;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\IngestFreshnessTool;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('hone-server.ingest_url', 'https://rust.example.test/ingest');
});

it('probes exactly the advertised origin once without credentials or redirects', function (string $advertised, string $expected): void {
    config()->set([
        'hone-server.ingest_url' => $advertised,
        'hone-server.health.ingest_connect_timeout' => 0.4,
        'hone-server.health.ingest_timeout' => 1.7,
    ]);

    $this->getJson('/capabilities')->assertOk()->assertJsonPath('ingest.url', $advertised);

    Http::fake(function (Request $request, array $options) use ($expected) {
        expect($request->url())->toBe($expected)
            ->and($request->method())->toBe('GET')
            ->and($request->body())->toBe('')
            ->and($request->hasHeader('Authorization'))->toBeFalse()
            ->and($options['connect_timeout'])->toBe(0.4)
            ->and($options['timeout'])->toBe(1.7)
            ->and($options['allow_redirects'])->toBeFalse();

        return Http::response('', 200);
    });

    expect(Artisan::call('hone:health', ['--json' => true]))->toBe(0);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

    expect($report['healthy'])->toBeTrue()
        ->and($report['checks']['ingest_target'])->toBe([
            'status' => 'ok', 'health_url' => $expected, 'http_status' => 200, 'reason' => 'reachable',
        ])
        ->and($output)->not->toContain('private-query', 'private-fragment');
    Http::assertSentCount(1);
})->with([
    'Rust' => ['https://rust.example.test/ingest', 'https://rust.example.test/up'],
    'prefix port query and fragment' => ['http://rust.example.test:8123/prefix/ingest?private-query=yes#private-fragment', 'http://rust.example.test:8123/up'],
    'explicit HTTPS port' => ['https://rust.example.test:8443/ingest', 'https://rust.example.test:8443/up'],
    'IPv6' => ['http://[::1]:8123/prefix/ingest?private-query=yes#private-fragment', 'http://[::1]:8123/up'],
]);

it('uses the same named PHP route fallback as capabilities', function (?string $configured): void {
    config()->set('hone-server.ingest_url', $configured);
    URL::forceRootUrl('https://php.example.test:8443/route-prefix');
    URL::forceScheme('https');

    $advertised = route('hone-server.ingest');
    expect($advertised)->toBe('https://php.example.test:8443/route-prefix/ingest');
    $this->getJson('https://php.example.test:8443/capabilities')->assertOk()->assertJsonPath('ingest.url', $advertised);
    Http::fake(['https://php.example.test:8443/up' => Http::response('', 200)]);

    $this->artisan('hone:health')->expectsOutputToContain('ingest_target')->assertExitCode(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://php.example.test:8443/up');
    Http::assertSentCount(1);
})->with(['unset' => [null], 'empty' => ['']]);

it('alarms in JSON and table output for every status other than 200', function (int $status, bool $json): void {
    Http::fake(['https://rust.example.test/up' => Http::response('private-response-body', $status, ['Location' => 'https://elsewhere.example.test/secret'])]);

    expect(Artisan::call('hone:health', ['--json' => $json]))->toBe(1);
    $output = Artisan::output();

    if ($json) {
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($report['healthy'])->toBeFalse()
            ->and($report['checks']['ingest_target']['status'])->toBe('alarm')
            ->and($report['checks']['ingest_target']['http_status'])->toBe($status);
    } else {
        expect($output)->toContain('ingest_target', 'alarm', 'unexpected_status');
    }

    expect($output)->not->toContain('private-response-body', 'elsewhere.example.test');
    Http::assertSentCount(1);
})->with([201, 204, 301, 302, 401, 503])->with([true, false]);

it('converts connection failures and timeouts into sanitized alarms', function (bool $json): void {
    Http::fake(['https://rust.example.test/up' => Http::failedConnection('private-exception-message')]);

    expect(Artisan::call('hone:health', ['--json' => $json]))->toBe(1);
    $output = Artisan::output();

    if ($json) {
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($report['healthy'])->toBeFalse()
            ->and($report['checks']['ingest_target']['status'])->toBe('alarm')
            ->and($report['checks']['ingest_target']['http_status'])->toBeNull();
    }

    expect($output)->toContain('ingest_target', 'alarm', 'connection_failed')
        ->not->toContain('private-exception-message', 'ConnectionException', 'Stack trace');
})->with([true, false]);

it('rejects invalid and userinfo-bearing URLs without a request or raw URL output', function (string $url, bool $json): void {
    config()->set('hone-server.ingest_url', $url);
    Http::fake();

    expect(Artisan::call('hone:health', ['--json' => $json]))->toBe(1);
    $output = Artisan::output();

    if ($json) {
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        expect($report['healthy'])->toBeFalse()
            ->and($report['checks']['ingest_target']['status'])->toBe('alarm')
            ->and($report['checks']['ingest_target']['health_url'])->toBeNull();
    }

    expect($output)->toContain('invalid_advertised_url')->not->toContain($url, 'private-secret');
    Http::assertNothingSent();
})->with([
    '/ingest?private-secret', '//rust.example.test/ingest?private-secret',
    'not a URL private-secret', 'ftp://rust.example.test/private-secret',
    'https://user:private-secret@rust.example.test/ingest',
    'https://private-secret@rust.example.test/ingest',
    'https://@rust.example.test/private-secret',
    'https://rust.example.test:99999/private-secret',
    "https://rust.example.test/ingest\nprivate-secret",
    'https://rust.example.test\\private-secret/ingest',
])->with([true, false]);

it('uses small default timeouts and clamps zero rather than disabling them', function (bool $zero): void {
    if ($zero) {
        config()->set('hone-server.health.ingest_connect_timeout', 0);
        config()->set('hone-server.health.ingest_timeout', 0);
    }

    Http::fake(function (Request $request, array $options) use ($zero) {
        expect($options['connect_timeout'])->toEqual($zero ? 0.05 : 1)
            ->and($options['timeout'])->toEqual($zero ? 0.05 : 3);

        return Http::response('', 200);
    });

    $this->artisan('hone:health')->expectsOutputToContain('ingest_target')->expectsOutputToContain('ok')->assertExitCode(0);
    Http::assertSentCount(1);
})->with([false, true]);

it('keeps maintenance reporting and MCP freshness free of HTTP probes', function (): void {
    Http::fake();
    expect(app(MaintenanceHealth::class)->report()['checks'])->not->toHaveKey('ingest_target');

    RequestEffectCeiling::publish(app('request'), Effect::Read->value);
    HoneMcpServer::tool(IngestFreshnessTool::class, [])->assertOk();

    Http::assertNothingSent();
});

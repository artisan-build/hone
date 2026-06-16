<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use ArtisanBuild\HoneServer\Mcp\Tools\CacheStatsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\CommandStatsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ExceptionsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\LogVolumeByLevelTool;
use ArtisanBuild\HoneServer\Mcp\Tools\MailVolumeTool;
use ArtisanBuild\HoneServer\Mcp\Tools\NotificationVolumeTool;
use ArtisanBuild\HoneServer\Mcp\Tools\QueryMetricTool;
use ArtisanBuild\HoneServer\Mcp\Tools\QueueThroughputTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RegressionCheckTool;
use ArtisanBuild\HoneServer\Mcp\Tools\ScheduledTaskHealthTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowJobsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowOutgoingRequestsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowQueriesTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowRequestsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\TopUsersTool;
use ArtisanBuild\HoneServer\Models\Aggregate;
use Illuminate\Support\Carbon;

function seedAggregateBucket(
    string $app,
    string $recordType,
    string $normalizedKey,
    Carbon $bucketDate,
    int $count,
    float $avg,
    float $max,
    float $p95,
    float $p99,
    ?string $deploy = null,
): void {
    foreach ([
        'count' => $count,
        'avg' => $avg,
        'max' => $max,
        'p95' => $p95,
        'p99' => $p99,
    ] as $metric => $value) {
        Aggregate::factory()->create([
            'app' => $app,
            'record_type' => $recordType,
            'normalized_key' => $normalizedKey,
            'deploy' => $deploy,
            'bucket_date' => $bucketDate,
            'metric' => $metric,
            'value' => $value,
            'sample_count' => $count,
        ]);
    }
}

it('combines daily aggregates with locked window math', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today->copy()->subDay(), 10, 20, 50, 45, 49);
    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today, 30, 40, 100, 90, 99);

    $rows = app(AggregateWindow::class)->topOffenders('query', 7, 'checkout', null, 'p95', 10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['normalized_key'])->toBe('select-users-by-id')
        ->and($rows[0]['count'])->toBe(40.0)
        ->and($rows[0]['sample_count'])->toBe(40)
        ->and($rows[0]['avg'])->toBe(35.0)
        ->and($rows[0]['max'])->toBe(100.0)
        ->and($rows[0]['p95'])->toBe(90.0)
        ->and($rows[0]['p99'])->toBe(99.0);
});

it('returns slow queries ordered by p95 and honors limit and app filter', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'query', 'fast-query', $today, 10, 15, 30, 25, 29);
    seedAggregateBucket('checkout', 'query', 'slow-query', $today, 10, 30, 80, 75, 79);
    seedAggregateBucket('billing', 'query', 'billing-query', $today, 10, 200, 300, 250, 299);

    $rows = app(AggregateWindow::class)->topOffenders('query', 7, 'checkout', null, 'p95', 1);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['normalized_key'])->toBe('slow-query')
        ->and($rows[0]['p95'])->toBe(75.0);

    HoneMcpServer::tool(SlowQueriesTool::class, [
        'app' => 'checkout',
        'metric' => 'p95',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertSee('slow-query')
        ->assertDontSee('fast-query')
        ->assertDontSee('billing-query');
});

it('excludes routeless request keys from the slow requests ranking', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    // Unmatched 404 traffic collapses to a bare method key and would otherwise win the p95 ranking.
    seedAggregateBucket('checkout', 'request', 'GET', $today, 172, 800, 4000, 2900, 3900);
    seedAggregateBucket('checkout', 'request', 'GET /posts/{post}', $today, 72, 300, 700, 560, 690);

    $rows = app(AggregateWindow::class)->topOffenders('request', 7, 'checkout', null, 'p95', 10, true);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['normalized_key'])->toBe('GET /posts/{post}');

    HoneMcpServer::tool(SlowRequestsTool::class, [
        'app' => 'checkout',
        'metric' => 'p95',
    ])
        ->assertOk()
        ->assertSee('GET /posts/{post}')
        ->assertDontSee('"GET"');
});

it('still ranks routeless keys for non-request metrics', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today, 10, 30, 80, 75, 79);

    $rows = app(AggregateWindow::class)->topOffenders('query', 7, 'checkout', null, 'p95', 10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['normalized_key'])->toBe('select-users-by-id');
});

it('returns generic query metrics over the requested window', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today->copy()->subDay(), 10, 20, 50, 45, 49);
    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today, 30, 40, 100, 90, 99);

    $rows = app(AggregateWindow::class)->metric('query', 'count', 'select-users-by-id', 'checkout', null, 7);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['value'])->toBe(40.0);

    HoneMcpServer::tool(QueryMetricTool::class, [
        'record_type' => 'query',
        'metric' => 'count',
        'normalized_key' => 'select-users-by-id',
        'app' => 'checkout',
    ])
        ->assertOk()
        ->assertSee('select-users-by-id')
        ->assertSee('40');
});

it('returns regression checks per deploy ordered by recency', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today->copy()->subDays(3), 10, 80, 100, 90, 99, 'old-deploy');
    seedAggregateBucket('checkout', 'query', 'select-users-by-id', $today, 20, 30, 60, 50, 59, 'new-deploy');

    $rows = app(AggregateWindow::class)->acrossDeploys('query', 'select-users-by-id', 'p95', 'checkout', 5);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['deploy'])->toBe('new-deploy')
        ->and($rows[0]['value'])->toBe(50.0)
        ->and($rows[1]['deploy'])->toBe('old-deploy')
        ->and($rows[1]['value'])->toBe(90.0);

    HoneMcpServer::tool(RegressionCheckTool::class, [
        'record_type' => 'query',
        'normalized_key' => 'select-users-by-id',
        'metric' => 'p95',
        'app' => 'checkout',
    ])
        ->assertOk()
        ->assertSee('new-deploy')
        ->assertSee('old-deploy');
});

it('registers and serves all metric tools', function (string $toolClass): void {
    HoneMcpServer::tool($toolClass, match ($toolClass) {
        QueryMetricTool::class => ['record_type' => 'query', 'metric' => 'count'],
        RegressionCheckTool::class => ['record_type' => 'query', 'normalized_key' => 'select-users-by-id', 'metric' => 'p95'],
        default => [],
    })->assertOk();

    $property = new ReflectionProperty(HoneMcpServer::class, 'tools');

    expect($property->getDefaultValue())->toContain($toolClass);
})->with([
    SlowRequestsTool::class,
    SlowQueriesTool::class,
    SlowJobsTool::class,
    SlowOutgoingRequestsTool::class,
    QueryMetricTool::class,
    RegressionCheckTool::class,
]);

it('returns log volume by level without exposing messages', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'log', 'error', $today, 5, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'log', 'info', $today, 20, 0, 0, 0, 0);

    HoneMcpServer::tool(LogVolumeByLevelTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('error')
        ->assertSee('info')
        ->assertSee('5')
        ->assertSee('20')
        ->assertDontSee('message');
});

it('returns top users ordered by user id count only', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'user', '42', $today, 9, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'user', '7', $today, 3, 0, 0, 0, 0);

    $rows = app(AggregateWindow::class)->topOffenders('user', 7, 'checkout', null, 'count', 10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['normalized_key'])->toBe('42')
        ->and($rows[0]['count'])->toBe(9.0)
        ->and($rows[1]['normalized_key'])->toBe('7')
        ->and($rows[1]['count'])->toBe(3.0);

    HoneMcpServer::tool(TopUsersTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('42')
        ->assertSee('7')
        ->assertDontSee('email')
        ->assertDontSee('username');
});

it('returns exception volume with the latest seen bucket date', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'exception', 'RuntimeException app/Actions/Checkout.php:12', $today->copy()->subDay(), 2, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'exception', 'RuntimeException app/Actions/Checkout.php:12', $today, 4, 0, 0, 0, 0);

    $rows = app(AggregateWindow::class)->topOffenders('exception', 7, 'checkout', null, 'count', 10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['count'])->toBe(6.0)
        ->and($rows[0]['last_bucket_date'])->toBe($today->toDateString());

    HoneMcpServer::tool(ExceptionsTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('RuntimeException app/Actions/Checkout.php:12')
        ->assertSee($today->toDateString())
        ->assertSee('6');
});

it('returns mail and queue throughput totals across keys and days', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'mail', 'App\\Mail\\Receipt', $today->copy()->subDay(), 4, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'mail', 'App\\Mail\\Welcome', $today, 6, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'queued-job', 'App\\Jobs\\CapturePayment', $today->copy()->subDay(), 7, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'queued-job', 'App\\Jobs\\SendReceipt', $today, 8, 0, 0, 0, 0);

    expect(app(AggregateWindow::class)->total('mail', 7, 'checkout')['count'])->toBe(10.0)
        ->and(app(AggregateWindow::class)->total('queued-job', 7, 'checkout')['count'])->toBe(15.0);

    HoneMcpServer::tool(MailVolumeTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('10');

    HoneMcpServer::tool(QueueThroughputTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('15')
        ->assertSee('App\\\\Jobs\\\\SendReceipt');
});

it('returns cache stats by store and type', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'cache-event', 'redis:hit', $today, 100, 0, 0, 0, 0);
    seedAggregateBucket('checkout', 'cache-event', 'redis:miss', $today, 10, 0, 0, 0, 0);

    HoneMcpServer::tool(CacheStatsTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('redis:hit')
        ->assertSee('redis:miss')
        ->assertSee('100')
        ->assertSee('10');
});

it('returns scheduled task and command duration stats by name', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'scheduled-task', 'reports:send', $today, 3, 25, 80, 70, 75);
    seedAggregateBucket('checkout', 'command', 'cache:warm', $today, 5, 12, 30, 25, 28);

    HoneMcpServer::tool(ScheduledTaskHealthTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('reports:send')
        ->assertSee('25')
        ->assertSee('80')
        ->assertSee('3');

    HoneMcpServer::tool(CommandStatsTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('cache:warm')
        ->assertSee('12')
        ->assertSee('30')
        ->assertSee('5');
});

it('registers and serves all volume tools', function (string $toolClass): void {
    HoneMcpServer::tool($toolClass)->assertOk();

    $property = new ReflectionProperty(HoneMcpServer::class, 'tools');

    expect($property->getDefaultValue())->toContain($toolClass);
})->with([
    ExceptionsTool::class,
    CacheStatsTool::class,
    QueueThroughputTool::class,
    MailVolumeTool::class,
    NotificationVolumeTool::class,
    ScheduledTaskHealthTool::class,
    CommandStatsTool::class,
    LogVolumeByLevelTool::class,
    TopUsersTool::class,
]);

it('keeps slow tool record type mappings aligned with stored Nightwatch values', function (string $toolClass, string $recordType): void {
    HoneMcpServer::tool($toolClass)
        ->assertOk()
        ->assertSee($recordType);
})->with([
    'requests' => [SlowRequestsTool::class, 'request'],
    'queries' => [SlowQueriesTool::class, 'query'],
    'jobs' => [SlowJobsTool::class, 'queued-job'],
    'outgoing requests' => [SlowOutgoingRequestsTool::class, 'outgoing-request'],
]);

it('returns seeded job and outgoing request offenders through their slow tools', function (): void {
    $today = Carbon::now('UTC')->startOfDay();

    seedAggregateBucket('checkout', 'queued-job', 'App\Jobs\SendWelcomeEmail', $today, 12, 80, 150, 120, 145);
    seedAggregateBucket('checkout', 'outgoing-request', 'GET api.example.com', $today, 8, 40, 90, 75, 85);

    $jobOffenders = app(AggregateWindow::class)->topOffenders('queued-job', 7, 'checkout', null, 'p95', 10);

    expect($jobOffenders)->toHaveCount(1)
        ->and($jobOffenders[0]['normalized_key'])->toBe('App\Jobs\SendWelcomeEmail')
        ->and($jobOffenders[0]['count'])->toBe(12.0)
        ->and($jobOffenders[0]['p95'])->toBe(120.0);

    HoneMcpServer::tool(SlowJobsTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('App\\\\Jobs\\\\SendWelcomeEmail')
        ->assertSee('120')
        ->assertSee('12');

    HoneMcpServer::tool(SlowOutgoingRequestsTool::class, ['app' => 'checkout'])
        ->assertOk()
        ->assertSee('GET api.example.com')
        ->assertSee('75')
        ->assertSee('8');
});

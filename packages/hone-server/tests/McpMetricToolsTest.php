<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Support\AggregateWindow;
use ArtisanBuild\HoneServer\Mcp\Tools\QueryMetricTool;
use ArtisanBuild\HoneServer\Mcp\Tools\RegressionCheckTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowJobsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowOutgoingRequestsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowQueriesTool;
use ArtisanBuild\HoneServer\Mcp\Tools\SlowRequestsTool;
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

<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Contracts\AsnLookup;
use ArtisanBuild\HoneServer\Contracts\NameserverResolver;
use ArtisanBuild\HoneServer\Jobs\ProcessTelemetryBatch;
use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\AwakeSegmentsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\BackgroundDbActivityTool;
use ArtisanBuild\HoneServer\Mcp\Tools\EdgeProfileTool;
use ArtisanBuild\HoneServer\Mcp\Tools\GuestDbRoutesTool;
use ArtisanBuild\HoneServer\Mcp\Tools\GuestTrafficClustersTool;
use ArtisanBuild\HoneServer\Models\ActivityBucket;
use ArtisanBuild\HoneServer\Models\BackgroundActivityBucket;
use ArtisanBuild\HoneServer\Models\RawEvent;
use ArtisanBuild\HoneServer\Models\RequestActivityBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
});

afterEach(function (): void {
    DB::connection('hone')->statement("SET TIME ZONE 'UTC'");
    Carbon::setTestNow();
});

it('returns scenario A compute and database segments with partitioned and sustained attribution', function (): void {
    seedIdleCostScenario('scenario-a', guestQueries: true);
    seedIdleCostScenario('another-app', guestQueries: false, backgroundQueries: true);

    $payload = awakeSegmentsPayload('scenario-a');

    expect($payload['compute_segments'])->toBe([
        expectedAwakeSegment(humanMinutes: 15, guestMinutes: 108, guestSustainedMinutes: 110),
    ])->and($payload['database_segments'])->toBe([
        expectedAwakeSegment(humanMinutes: 15, guestMinutes: 108, guestSustainedMinutes: 110),
    ])->and($payload['metric_definitions']['minutes'])->toContain('partition')
        ->and($payload['metric_definitions']['minutes'])->toContain('human > guest > background')
        ->and($payload['metric_definitions']['sustained_minutes'])->toContain('overlap');
});

it('returns scenario B compute unchanged and limits database wake time to humans', function (): void {
    seedIdleCostScenario('scenario-b', guestQueries: false);

    $payload = awakeSegmentsPayload('scenario-b');

    expect($payload['compute_segments'])->toBe([
        expectedAwakeSegment(humanMinutes: 15, guestMinutes: 108, guestSustainedMinutes: 110),
    ])->and($payload['database_segments'])->toBe([
        [
            'from' => '2026-06-09T09:00:00Z',
            'to' => '2026-06-09T09:15:00Z',
            'duration_minutes' => 15,
            'classes' => [
                'human' => ['minutes' => 15, 'sustained_minutes' => 15],
                'guest' => ['minutes' => 0, 'sustained_minutes' => 0],
                'background' => ['minutes' => 0, 'sustained_minutes' => 0],
            ],
        ],
    ]);
});

it('preserves bucket instants when the PostgreSQL session uses a non-UTC timezone', function (): void {
    seedActivityBucket('timezone-app', '2026-06-09 09:00:00+00', ['human_requests' => 1]);

    DB::connection('hone')->statement("SET TIME ZONE 'Asia/Tokyo'");

    $payload = awakeSegmentsPayload(
        'timezone-app',
        from: '2026-06-09T09:00:00Z',
        to: '2026-06-09T09:00:00Z',
    );

    expect($payload['compute_segments'][0]['from'])->toBe('2026-06-09T09:00:00Z')
        ->and($payload['compute_segments'][0]['to'])->toBe('2026-06-09T09:05:00Z')
        ->and($payload['database_segments'][0]['from'])->toBe('2026-06-09T09:00:00Z')
        ->and($payload['database_segments'][0]['to'])->toBe('2026-06-09T09:05:00Z');
});

it('returns scenario C overlapping background coverage and scheduled database frequency', function (): void {
    seedIdleCostScenario('scenario-c', guestQueries: true, backgroundQueries: true);

    $segments = awakeSegmentsPayload('scenario-c');
    $background = honeToolPayload(HoneMcpServer::tool(BackgroundDbActivityTool::class, idleCostWindow('scenario-c'))->assertOk());

    $expected = expectedAwakeSegment(
        humanMinutes: 15,
        guestMinutes: 108,
        guestSustainedMinutes: 110,
        backgroundSustainedMinutes: 123,
    );

    expect($segments['compute_segments'])->toBe([$expected])
        ->and($segments['database_segments'])->toBe([$expected])
        ->and($background['background_classes'])->toBe(['scheduled', 'job'])
        ->and($background['window']['minutes'])->toBe(119)
        ->and($background['activities'])->toBe([
            [
                'class' => 'scheduled',
                'name' => 'schedule:run',
                'runs' => 119,
                'active_minutes' => 119,
                'runs_per_minute' => 1,
            ],
        ]);
});

it('merges activity at and before an idle edge and splits activity one bucket tick after it', function (): void {
    seedActivityBucket('before-edge', '2026-06-09 09:00:00+00', ['human_requests' => 1]);
    seedActivityBucket('before-edge', '2026-06-09 09:04:00+00', ['guest_requests' => 1]);
    seedActivityBucket('at-edge', '2026-06-09 09:00:00+00', ['human_requests' => 1]);
    seedActivityBucket('at-edge', '2026-06-09 09:05:00+00', ['guest_requests' => 1]);
    seedActivityBucket('after-edge', '2026-06-09 09:00:00+00', ['human_requests' => 1]);
    seedActivityBucket('after-edge', '2026-06-09 09:06:00+00', ['guest_requests' => 1]);

    $before = awakeSegmentsPayload('before-edge', to: '2026-06-09T09:04:00+00:00');
    $at = awakeSegmentsPayload('at-edge', to: '2026-06-09T09:05:00+00:00');
    $after = awakeSegmentsPayload('after-edge', to: '2026-06-09T09:06:00+00:00');

    expect($before['compute_segments'])->toHaveCount(1)
        ->and($before['compute_segments'][0]['to'])->toBe('2026-06-09T09:09:00Z')
        ->and($at['compute_segments'])->toHaveCount(1)
        ->and($at['compute_segments'][0]['to'])->toBe('2026-06-09T09:10:00Z')
        ->and($after['compute_segments'])->toHaveCount(2)
        ->and($after['compute_segments'][0]['to'])->toBe('2026-06-09T09:05:00Z')
        ->and($after['compute_segments'][1]['from'])->toBe('2026-06-09T09:06:00Z')
        ->and(segmentContains($after['compute_segments'][0], '2026-06-09T09:04:59Z'))->toBeTrue()
        ->and(segmentContains($after['compute_segments'][0], '2026-06-09T09:05:00Z'))->toBeFalse()
        ->and(segmentContains($after['compute_segments'][0], '2026-06-09T09:05:01Z'))->toBeFalse()
        ->and($after['metric_definitions']['segment_bounds'])->toContain('[from, to)');
});

it('includes query-running jobs and excludes background runs without queries from database activity', function (): void {
    seedActivityBucket('background-types', '2026-06-09 09:00:00+00', [
        'scheduled_runs_without_queries' => 1,
        'jobs_with_queries' => 2,
        'jobs_without_queries' => 3,
    ]);
    BackgroundActivityBucket::factory()->create([
        'app' => 'background-types',
        'bucket_minute' => '2026-06-09 09:00:00+00',
        'activity_type' => 'job',
        'identity' => 'App\\Jobs\\SyncCatalog',
        'runs_with_queries' => 2,
    ]);

    $background = honeToolPayload(HoneMcpServer::tool(BackgroundDbActivityTool::class, idleCostWindow(
        'background-types',
        to: '2026-06-09T09:00:00+00:00',
    ))->assertOk());
    $segments = awakeSegmentsPayload('background-types', to: '2026-06-09T09:00:00+00:00');

    expect($background['activities'])->toBe([
        [
            'class' => 'job',
            'name' => 'App\\Jobs\\SyncCatalog',
            'runs' => 2,
            'active_minutes' => 1,
            'runs_per_minute' => 2,
        ],
    ])->and($segments['compute_segments'][0]['classes']['background']['sustained_minutes'])->toBe(5)
        ->and($segments['database_segments'][0]['classes']['background']['sustained_minutes'])->toBe(5);
});

it('durably lists named background database activity through ingest rollup and raw pruning', function (): void {
    $records = [
        backgroundRecord('scheduled-task', 'reports:send', '2026-06-09T09:00:00Z'),
        backgroundRecord('scheduled-task', 'reports:send', '2026-06-09T09:01:00Z'),
        backgroundRecord('scheduled-task', 'reports:send', '2026-06-09T09:02:00Z'),
        backgroundRecord('scheduled-task', 'cache:warm', '2026-06-09T09:00:00Z'),
        backgroundRecord('scheduled-task', 'cache:warm', '2026-06-09T09:02:00Z'),
        backgroundRecord('job-attempt', 'App\\Jobs\\SyncCatalog', '2026-06-09T09:01:00Z'),
    ];

    (new ProcessTelemetryBatch(
        app: 'production-path',
        deploy: null,
        sentAt: '2026-06-09T09:02:00Z',
        records: $records,
    ))->handle(app(AsnLookup::class));

    expect(RawEvent::query()->where('app', 'production-path')->pluck('normalized_key')->sort()->values()->all())->toBe([
        'App\\Jobs\\SyncCatalog',
        'cache:warm',
        'cache:warm',
        'reports:send',
        'reports:send',
        'reports:send',
    ]);

    Artisan::call('hone:rollup');
    Artisan::call('hone:rollup');

    $arguments = idleCostWindow(
        'production-path',
        from: '2026-06-09T09:00:00Z',
        to: '2026-06-09T09:02:00Z',
    );
    DB::connection('hone')->flushQueryLog();
    DB::connection('hone')->enableQueryLog();
    $beforePrune = honeToolPayload(HoneMcpServer::tool(BackgroundDbActivityTool::class, $arguments)->assertOk());
    $toolQueries = collect(DB::connection('hone')->getQueryLog());
    DB::connection('hone')->disableQueryLog();

    expect($beforePrune['activities'])->toBe([
        [
            'class' => 'job',
            'name' => 'App\\Jobs\\SyncCatalog',
            'runs' => 1,
            'active_minutes' => 1,
            'runs_per_minute' => 0.333333,
        ],
        [
            'class' => 'scheduled',
            'name' => 'cache:warm',
            'runs' => 2,
            'active_minutes' => 2,
            'runs_per_minute' => 0.666667,
        ],
        [
            'class' => 'scheduled',
            'name' => 'reports:send',
            'runs' => 3,
            'active_minutes' => 3,
            'runs_per_minute' => 1,
        ],
    ])->and($toolQueries)->toHaveCount(1)
        ->and($toolQueries->first()['query'])->toContain('from "background_activity_buckets"')
        ->and($toolQueries->first()['query'])->toContain('"app" = ?')
        ->and($toolQueries->first()['query'])->toContain('"bucket_minute" between ? and ?')
        ->and($toolQueries->first()['query'])->not->toContain('raw_events');

    $awake = awakeSegmentsPayload(
        'production-path',
        from: '2026-06-09T09:00:00Z',
        to: '2026-06-09T09:02:00Z',
    );

    expect($awake['compute_segments'])->toBe([backgroundOnlySegment()])
        ->and($awake['database_segments'])->toBe([backgroundOnlySegment()]);

    config()->set('hone-server.retention.raw_hours', 1);
    Artisan::call('hone:prune');

    $afterPrune = honeToolPayload(HoneMcpServer::tool(BackgroundDbActivityTool::class, $arguments)->assertOk());

    expect(RawEvent::query()->where('app', 'production-path')->count())->toBe(0)
        ->and(BackgroundActivityBucket::query()->where('app', 'production-path')->count())->toBe(6)
        ->and($afterPrune['activities'])->toBe($beforePrune['activities']);
});

it('returns query-running guest routes with exact response facts and excludes non-query routes', function (): void {
    seedIdleCostScenario('scenario-a', guestQueries: true);
    RequestActivityBucket::factory()->create([
        'app' => 'scenario-a',
        'bucket_minute' => '2026-06-09 09:13:00+00',
        'actor' => 'guest',
        'path' => '/static',
        'user_agent' => 'ScenarioBot/1.0',
        'asn' => 13335,
        'ran_queries' => false,
        'sets_cookie' => false,
        'cache_control' => 'public, max-age=300',
        'vary' => 'Accept-Encoding',
        'hits' => 1,
    ]);

    $payload = honeToolPayload(HoneMcpServer::tool(GuestDbRoutesTool::class, idleCostWindow('scenario-a'))->assertOk());

    expect($payload['routes'])->toBe([
        [
            'path' => '/',
            'hits' => 36,
            'response_facts' => [[
                'sets_cookie' => true,
                'cache_control' => 'no-cache, private',
                'vary' => 'Cookie',
                'hits' => 36,
            ]],
        ],
    ])->and(collect($payload['routes'])->pluck('path')->all())->not->toContain('/static');
});

it('attributes scenario A guest wake time to one path user agent and ASN cluster', function (): void {
    seedIdleCostScenario('scenario-a', guestQueries: true);

    $payload = honeToolPayload(HoneMcpServer::tool(GuestTrafficClustersTool::class, idleCostWindow('scenario-a'))->assertOk());

    expect($payload['window']['compute_idle_minutes'])->toBe(5)
        ->and($payload['total_clusters'])->toBe(1)
        ->and($payload['clusters'])->toBe([[
            'path' => '/',
            'user_agent' => 'ScenarioBot/1.0',
            'asn' => 13335,
            'volume' => 36,
            'awake_minutes' => 108,
        ]]);
});

it('caps guest route cluster and edge profile response sizes deterministically', function (): void {
    foreach (range(1, 101) as $route) {
        RequestActivityBucket::factory()->create([
            'app' => 'bounded-output',
            'bucket_minute' => '2026-06-09 09:13:00+00',
            'actor' => 'guest',
            'path' => sprintf('/route-%03d', $route),
            'host' => 'bounded.example.com',
            'user_agent' => sprintf('Bot/%03d', $route),
            'asn' => 64000 + $route,
            'ran_queries' => true,
            'sets_cookie' => false,
            'cache_control' => 'public, max-age=300',
            'vary' => 'Accept-Encoding',
            'hits' => 1,
        ]);
    }

    $window = idleCostWindow('bounded-output');
    $routes = honeToolPayload(HoneMcpServer::tool(GuestDbRoutesTool::class, $window)->assertOk());
    $clusters = honeToolPayload(HoneMcpServer::tool(GuestTrafficClustersTool::class, $window)->assertOk());
    app()->instance(NameserverResolver::class, nameserverResolver([]));
    $edge = honeToolPayload(HoneMcpServer::tool(EdgeProfileTool::class, ['app' => 'bounded-output'])->assertOk());

    expect($routes['total_routes'])->toBe(101)
        ->and($routes['routes'])->toHaveCount(100)
        ->and($routes['truncated'])->toBeTrue()
        ->and($clusters['total_clusters'])->toBe(101)
        ->and($clusters['clusters'])->toHaveCount(100)
        ->and($clusters['truncated'])->toBeTrue()
        ->and($edge['total_routes'])->toBe(101)
        ->and($edge['routes'])->toHaveCount(100)
        ->and($edge['truncated'])->toBeTrue();
});

it('uses only normalized nameserver records for Cloudflare detection and ignores CF-Ray', function (): void {
    seedEdgeProfile('edge-app');
    RawEvent::factory()->create([
        'app' => 'edge-app',
        'record_type' => 'request',
        'actor' => 'guest',
        'payload' => ['headers' => ['CF-Ray' => ['decoy-ray']]],
    ]);

    app()->instance(NameserverResolver::class, nameserverResolver(['ADA.NS.CLOUDFLARE.COM.']));
    $cloudflare = honeToolPayload(HoneMcpServer::tool(EdgeProfileTool::class, ['app' => 'edge-app'])->assertOk());

    app()->instance(NameserverResolver::class, nameserverResolver(['ns1.example.net.', 'NS2.EXAMPLE.NET']));
    $notCloudflare = honeToolPayload(HoneMcpServer::tool(EdgeProfileTool::class, ['app' => 'edge-app'])->assertOk());

    expect($cloudflare['domain'])->toBe('shop.example.com')
        ->and($cloudflare['nameservers'])->toBe(['ada.ns.cloudflare.com'])
        ->and($cloudflare['on_cloudflare'])->toBeTrue()
        ->and($cloudflare['routes'][0]['varies_by_user'])->toBeTrue()
        ->and($notCloudflare['nameservers'])->toBe(['ns1.example.net', 'ns2.example.net'])
        ->and($notCloudflare['on_cloudflare'])->toBeFalse();
});

it('keeps guest route and cluster facts after raw telemetry is pruned', function (): void {
    $records = [];

    foreach ([13, 16, 19] as $minute) {
        $records[] = guestRequestRecord('/', sprintf('2026-06-09T09:%02d:00Z', $minute), true);
    }

    $records[] = guestRequestRecord('/static', '2026-06-09T09:13:00Z', false);

    (new ProcessTelemetryBatch(
        app: 'durable-guests',
        deploy: null,
        sentAt: '2026-06-09T09:19:00Z',
        records: $records,
    ))->handle(app(AsnLookup::class));

    Artisan::call('hone:rollup');

    $window = idleCostWindow(
        'durable-guests',
        from: '2026-06-09T09:13:00Z',
        to: '2026-06-09T09:19:00Z',
    );
    $routesBeforePrune = honeToolPayload(HoneMcpServer::tool(GuestDbRoutesTool::class, $window)->assertOk());
    $clustersBeforePrune = honeToolPayload(HoneMcpServer::tool(GuestTrafficClustersTool::class, $window)->assertOk());

    config()->set('hone-server.retention.raw_hours', 1);
    Artisan::call('hone:prune');

    expect(RawEvent::query()->where('app', 'durable-guests')->count())->toBe(0)
        ->and(RequestActivityBucket::query()->where('app', 'durable-guests')->count())->toBe(4)
        ->and(honeToolPayload(HoneMcpServer::tool(GuestDbRoutesTool::class, $window)->assertOk()))->toBe($routesBeforePrune)
        ->and(honeToolPayload(HoneMcpServer::tool(GuestTrafficClustersTool::class, $window)->assertOk()))->toBe($clustersBeforePrune);
});

it('registers all five idle-cost tools and explains both attribution metrics in discovery metadata', function (): void {
    $property = new ReflectionProperty(HoneMcpServer::class, 'tools');
    $tools = $property->getDefaultValue();
    $description = (new AwakeSegmentsTool)->toArray()['description'];

    expect($tools)->toContain(
        AwakeSegmentsTool::class,
        BackgroundDbActivityTool::class,
        GuestDbRoutesTool::class,
        GuestTrafficClustersTool::class,
        EdgeProfileTool::class,
    )
        ->and($description)->toContain('partitioned minutes')
        ->and($description)->toContain('sustained_minutes')
        ->and($description)->toContain('overlap');
});

it('validates timeline windows and idle timeouts', function (string $tool, array $arguments): void {
    HoneMcpServer::tool($tool, $arguments)->assertHasErrors();
})->with([
    'awake missing from' => [AwakeSegmentsTool::class, [
        'app' => 'checkout',
        'to' => '2026-06-09T10:58:00+00:00',
        'compute_idle_minutes' => 5,
        'db_idle_minutes' => 5,
    ]],
    'background missing to' => [BackgroundDbActivityTool::class, [
        'app' => 'checkout',
        'from' => '2026-06-09T09:00:00+00:00',
    ]],
    'guest routes missing to' => [GuestDbRoutesTool::class, [
        'app' => 'checkout',
        'from' => '2026-06-09T09:00:00+00:00',
    ]],
    'guest clusters missing from' => [GuestTrafficClustersTool::class, [
        'app' => 'checkout',
        'to' => '2026-06-09T10:58:00+00:00',
    ]],
    'edge oversized app id' => [EdgeProfileTool::class, [
        'app' => str_repeat('a', 256),
    ]],
    'reversed window' => [AwakeSegmentsTool::class, [
        ...idleCostWindow('checkout'),
        'from' => '2026-06-09T11:00:00+00:00',
    ]],
    'non-minute timestamp' => [BackgroundDbActivityTool::class, [
        ...idleCostWindow('checkout'),
        'from' => '2026-06-09T09:00:01+00:00',
    ]],
    'zero compute timeout' => [AwakeSegmentsTool::class, [
        ...idleCostWindow('checkout'),
        'compute_idle_minutes' => 0,
        'db_idle_minutes' => 5,
    ]],
    'excessive database timeout' => [AwakeSegmentsTool::class, [
        ...idleCostWindow('checkout'),
        'compute_idle_minutes' => 5,
        'db_idle_minutes' => 1441,
    ]],
    'window over 31 days' => [BackgroundDbActivityTool::class, [
        'app' => 'checkout',
        'from' => '2026-05-01T00:00:00Z',
        'to' => '2026-06-02T00:00:00Z',
    ]],
    'oversized app id' => [AwakeSegmentsTool::class, [
        ...idleCostWindow(str_repeat('a', 256)),
        'compute_idle_minutes' => 5,
        'db_idle_minutes' => 5,
    ]],
]);

it('accepts exact timeline window and idle timeout bounds', function (): void {
    HoneMcpServer::tool(AwakeSegmentsTool::class, [
        'app' => 'checkout',
        'from' => '2026-05-09T09:00:00Z',
        'to' => '2026-06-09T09:00:00Z',
        'compute_idle_minutes' => 1,
        'db_idle_minutes' => 1440,
    ])->assertOk();

    HoneMcpServer::tool(BackgroundDbActivityTool::class, [
        'app' => 'checkout',
        'from' => '2026-05-09T09:00:00Z',
        'to' => '2026-06-09T09:00:00Z',
    ])->assertOk();

    HoneMcpServer::tool(GuestDbRoutesTool::class, [
        'app' => 'checkout',
        'from' => '2026-05-09T09:00:00Z',
        'to' => '2026-06-09T09:00:00Z',
    ])->assertOk();

    HoneMcpServer::tool(GuestTrafficClustersTool::class, [
        'app' => 'checkout',
        'from' => '2026-05-09T09:00:00Z',
        'to' => '2026-06-09T09:00:00Z',
    ])->assertOk();

    HoneMcpServer::tool(EdgeProfileTool::class, ['app' => str_repeat('a', 255)])->assertOk();
});

it('matches the committed output schema snapshot for :tool', function (string $tool): void {
    seedIdleCostScenario('snapshot-app', guestQueries: true, backgroundQueries: true);
    seedEdgeProfile('snapshot-app');
    app()->instance(NameserverResolver::class, nameserverResolver(['ada.ns.cloudflare.com']));

    $arguments = match ($tool) {
        AwakeSegmentsTool::class => [
            ...idleCostWindow('snapshot-app'),
            'compute_idle_minutes' => 5,
            'db_idle_minutes' => 5,
        ],
        BackgroundDbActivityTool::class,
        GuestDbRoutesTool::class,
        GuestTrafficClustersTool::class => idleCostWindow('snapshot-app'),
        EdgeProfileTool::class => ['app' => 'snapshot-app'],
    };
    $payload = honeToolPayload(HoneMcpServer::tool($tool, $arguments)->assertOk());

    expect(honeOutputSchema($payload))->toMatchSnapshot();
})->with([
    'awake_segments' => AwakeSegmentsTool::class,
    'background_db_activity' => BackgroundDbActivityTool::class,
    'guest_db_routes' => GuestDbRoutesTool::class,
    'guest_traffic_clusters' => GuestTrafficClustersTool::class,
    'edge_profile' => EdgeProfileTool::class,
]);

/**
 * @return array<string, mixed>
 */
function awakeSegmentsPayload(
    string $app,
    string $from = '2026-06-09T09:00:00+00:00',
    string $to = '2026-06-09T10:58:00+00:00',
): array {
    return honeToolPayload(HoneMcpServer::tool(AwakeSegmentsTool::class, [
        ...idleCostWindow($app, $from, $to),
        'compute_idle_minutes' => 5,
        'db_idle_minutes' => 5,
    ])->assertOk());
}

/**
 * @return array{app: string, from: string, to: string}
 */
function idleCostWindow(
    string $app,
    string $from = '2026-06-09T09:00:00+00:00',
    string $to = '2026-06-09T10:58:00+00:00',
): array {
    return compact('app', 'from', 'to');
}

function seedIdleCostScenario(string $app, bool $guestQueries, bool $backgroundQueries = false): void
{
    /** @var array<string, array<string, int>> $buckets */
    $buckets = [];

    for ($minute = 0; $minute <= 10; $minute += 2) {
        incrementActivity($buckets, CarbonImmutable::parse('2026-06-09 09:00:00+00')->addMinutes($minute), 'human_requests');
    }

    for (
        $minute = CarbonImmutable::parse('2026-06-09 09:13:00+00');
        $minute->lessThanOrEqualTo(CarbonImmutable::parse('2026-06-09 10:58:00+00'));
        $minute = $minute->addMinutes(3)
    ) {
        incrementActivity($buckets, $minute, 'guest_requests');

        if ($guestQueries) {
            incrementActivity($buckets, $minute, 'guest_requests_with_queries');
        }

        RequestActivityBucket::factory()->create([
            'app' => $app,
            'bucket_minute' => $minute,
            'actor' => 'guest',
            'path' => $guestQueries ? '/' : '/static',
            'host' => 'shop.example.com',
            'user_agent' => 'ScenarioBot/1.0',
            'asn' => 13335,
            'ran_queries' => $guestQueries,
            'sets_cookie' => $guestQueries,
            'cache_control' => $guestQueries ? 'no-cache, private' : 'public, max-age=300',
            'vary' => $guestQueries ? 'Cookie' : 'Accept-Encoding',
            'hits' => 1,
        ]);
    }

    if ($backgroundQueries) {
        for (
            $minute = CarbonImmutable::parse('2026-06-09 09:00:00+00');
            $minute->lessThanOrEqualTo(CarbonImmutable::parse('2026-06-09 10:58:00+00'));
            $minute = $minute->addMinute()
        ) {
            incrementActivity($buckets, $minute, 'scheduled_runs_with_queries');
            BackgroundActivityBucket::factory()->create([
                'app' => $app,
                'bucket_minute' => $minute,
                'activity_type' => 'scheduled',
                'identity' => 'schedule:run',
                'runs_with_queries' => 1,
            ]);
        }
    }

    foreach ($buckets as $bucketMinute => $counts) {
        seedActivityBucket($app, $bucketMinute, $counts);
    }
}

/**
 * @param  array<string, array<string, int>>  $buckets
 */
function incrementActivity(array &$buckets, CarbonImmutable $minute, string $column): void
{
    $bucketMinute = $minute->toIso8601String();
    $buckets[$bucketMinute] ??= [];
    $buckets[$bucketMinute][$column] = ($buckets[$bucketMinute][$column] ?? 0) + 1;
}

/**
 * @param  array<string, int>  $counts
 */
function seedActivityBucket(string $app, string $bucketMinute, array $counts): void
{
    ActivityBucket::factory()->create([
        'app' => $app,
        'bucket_minute' => CarbonImmutable::parse($bucketMinute),
        'human_requests' => 0,
        'guest_requests' => 0,
        'guest_requests_with_queries' => 0,
        'scheduled_runs_with_queries' => 0,
        'scheduled_runs_without_queries' => 0,
        'jobs_with_queries' => 0,
        'jobs_without_queries' => 0,
        ...$counts,
    ]);
}

/**
 * @return array<string, mixed>
 */
function expectedAwakeSegment(
    int $humanMinutes,
    int $guestMinutes,
    int $guestSustainedMinutes,
    int $backgroundSustainedMinutes = 0,
): array {
    return [
        'from' => '2026-06-09T09:00:00Z',
        'to' => '2026-06-09T11:03:00Z',
        'duration_minutes' => 123,
        'classes' => [
            'human' => ['minutes' => $humanMinutes, 'sustained_minutes' => 15],
            'guest' => ['minutes' => $guestMinutes, 'sustained_minutes' => $guestSustainedMinutes],
            'background' => ['minutes' => 0, 'sustained_minutes' => $backgroundSustainedMinutes],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $segment
 */
function segmentContains(array $segment, string $timestamp): bool
{
    $tick = CarbonImmutable::parse($timestamp);

    return $tick->greaterThanOrEqualTo(CarbonImmutable::parse($segment['from']))
        && $tick->lessThan(CarbonImmutable::parse($segment['to']));
}

/**
 * @return array<string, mixed>
 */
function backgroundRecord(string $recordType, string $name, string $timestamp): array
{
    return [
        'v' => 1,
        't' => $recordType,
        'name' => $name,
        'status' => 'processed',
        'queries' => 1,
        'timestamp' => $timestamp,
    ];
}

/**
 * @return array<string, mixed>
 */
function backgroundOnlySegment(): array
{
    return [
        'from' => '2026-06-09T09:00:00Z',
        'to' => '2026-06-09T09:07:00Z',
        'duration_minutes' => 7,
        'classes' => [
            'human' => ['minutes' => 0, 'sustained_minutes' => 0],
            'guest' => ['minutes' => 0, 'sustained_minutes' => 0],
            'background' => ['minutes' => 7, 'sustained_minutes' => 7],
        ],
    ];
}

function seedEdgeProfile(string $app): void
{
    RequestActivityBucket::factory()->create([
        'app' => $app,
        'bucket_minute' => '2026-06-09 09:13:00+00',
        'actor' => 'guest',
        'path' => '/',
        'host' => 'shop.example.com',
        'user_agent' => 'ScenarioBot/1.0',
        'asn' => 13335,
        'ran_queries' => true,
        'sets_cookie' => true,
        'cache_control' => 'no-cache, private',
        'vary' => 'Cookie',
        'hits' => 1,
    ]);
    RequestActivityBucket::factory()->create([
        'app' => $app,
        'bucket_minute' => '2026-06-09 09:14:00+00',
        'actor' => 'human',
        'path' => '/',
        'host' => 'shop.example.com',
        'user_agent' => null,
        'asn' => 13335,
        'ran_queries' => true,
        'sets_cookie' => true,
        'cache_control' => 'private, no-store',
        'vary' => 'Cookie',
        'hits' => 1,
    ]);
}

function nameserverResolver(array $nameservers): NameserverResolver
{
    return new class($nameservers) implements NameserverResolver
    {
        /** @param list<string> $nameservers */
        public function __construct(private readonly array $nameservers) {}

        public function resolve(string $domain): array
        {
            return $this->nameservers;
        }
    };
}

/**
 * @return array<string, mixed>
 */
function guestRequestRecord(string $path, string $timestamp, bool $ranQueries): array
{
    return [
        'v' => 1,
        't' => 'request',
        'method' => 'GET',
        'route_path' => $path,
        'user' => '',
        'queries' => $ranQueries ? 1 : 0,
        'ip' => '1.1.1.1',
        'timestamp' => $timestamp,
        'headers' => json_encode([
            'Host' => ['shop.example.com'],
            'User-Agent' => ['ScenarioBot/1.0'],
        ], JSON_THROW_ON_ERROR),
        'context' => json_encode([
            'hone.response' => [
                'sets_cookie' => $ranQueries,
                'cache_control' => $ranQueries ? 'no-cache, private' : 'public, max-age=300',
                'vary' => $ranQueries ? 'Cookie' : 'Accept-Encoding',
            ],
        ], JSON_THROW_ON_ERROR),
    ];
}

/**
 * @return array<string, mixed>
 */
function honeOutputSchema(mixed $value): array
{
    if (is_array($value)) {
        if (array_is_list($value)) {
            return [
                'type' => 'array',
                'items' => $value === [] ? null : honeOutputSchema($value[0]),
            ];
        }

        return [
            'type' => 'object',
            'required' => array_keys($value),
            'properties' => array_map(honeOutputSchema(...), $value),
        ];
    }

    return ['type' => match (get_debug_type($value)) {
        'bool' => 'boolean',
        'int' => 'integer',
        'float' => 'number',
        'null' => 'null',
        default => get_debug_type($value),
    }];
}

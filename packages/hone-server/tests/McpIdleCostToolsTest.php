<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Mcp\HoneMcpServer;
use ArtisanBuild\HoneServer\Mcp\Tools\AwakeSegmentsTool;
use ArtisanBuild\HoneServer\Mcp\Tools\BackgroundDbActivityTool;
use ArtisanBuild\HoneServer\Models\ActivityBucket;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-06-09 12:00:00+00');
});

afterEach(function (): void {
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

    $background = honeToolPayload(HoneMcpServer::tool(BackgroundDbActivityTool::class, idleCostWindow(
        'background-types',
        to: '2026-06-09T09:00:00+00:00',
    ))->assertOk());
    $segments = awakeSegmentsPayload('background-types', to: '2026-06-09T09:00:00+00:00');

    expect($background['activities'])->toBe([
        [
            'class' => 'job',
            'runs' => 2,
            'active_minutes' => 1,
            'runs_per_minute' => 2,
        ],
    ])->and($segments['compute_segments'][0]['classes']['background']['sustained_minutes'])->toBe(5)
        ->and($segments['database_segments'][0]['classes']['background']['sustained_minutes'])->toBe(5);
});

it('registers both read-only tools and explains both attribution metrics in discovery metadata', function (): void {
    $property = new ReflectionProperty(HoneMcpServer::class, 'tools');
    $tools = $property->getDefaultValue();
    $description = (new AwakeSegmentsTool)->toArray()['description'];

    expect($tools)->toContain(AwakeSegmentsTool::class, BackgroundDbActivityTool::class)
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
});

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
    }

    if ($backgroundQueries) {
        for (
            $minute = CarbonImmutable::parse('2026-06-09 09:00:00+00');
            $minute->lessThanOrEqualTo(CarbonImmutable::parse('2026-06-09 10:58:00+00'));
            $minute = $minute->addMinute()
        ) {
            incrementActivity($buckets, $minute, 'scheduled_runs_with_queries');
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

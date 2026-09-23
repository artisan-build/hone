<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Support;

use ArtisanBuild\HoneServer\Models\ActivityBucket;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use UnexpectedValueException;

final class AwakeSegmentAnalysis
{
    /** @var list<string> */
    private const ACTIVITY_CLASSES = ['human', 'guest', 'background'];

    /**
     * @return array{compute: list<array<string, mixed>>, database: list<array<string, mixed>>}
     */
    public function forApp(
        string $app,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $computeIdleMinutes,
        int $databaseIdleMinutes,
    ): array {
        $intervals = [
            'compute' => $this->emptyClassIntervals(),
            'database' => $this->emptyClassIntervals(),
        ];

        $buckets = ActivityBucket::query()
            ->select([
                'bucket_minute',
                'human_requests',
                'guest_requests',
                'guest_requests_with_queries',
                'scheduled_runs_with_queries',
                'scheduled_runs_without_queries',
                'jobs_with_queries',
                'jobs_without_queries',
            ])
            ->where('app', $app)
            ->whereBetween('bucket_minute', [$from->toIso8601String(), $to->toIso8601String()])
            ->orderBy('bucket_minute')
            ->cursor();

        foreach ($buckets as $bucket) {
            $bucketMinute = $bucket->getAttribute('bucket_minute');

            if (! $bucketMinute instanceof DateTimeInterface) {
                throw new UnexpectedValueException('Activity bucket minute must be a date-time value.');
            }

            $minute = CarbonImmutable::instance($bucketMinute)->utc();
            $hasHumanActivity = (int) $bucket->getAttribute('human_requests') > 0;
            $hasGuestActivity = (int) $bucket->getAttribute('guest_requests') > 0;
            $hasGuestDatabaseActivity = (int) $bucket->getAttribute('guest_requests_with_queries') > 0;
            $hasBackgroundDatabaseActivity = (int) $bucket->getAttribute('scheduled_runs_with_queries')
                + (int) $bucket->getAttribute('jobs_with_queries') > 0;
            $hasBackgroundActivity = $hasBackgroundDatabaseActivity
                || (int) $bucket->getAttribute('scheduled_runs_without_queries')
                    + (int) $bucket->getAttribute('jobs_without_queries') > 0;

            $this->addActivity($intervals['compute'], 'human', $hasHumanActivity, $minute, $computeIdleMinutes);
            $this->addActivity($intervals['compute'], 'guest', $hasGuestActivity, $minute, $computeIdleMinutes);
            $this->addActivity($intervals['compute'], 'background', $hasBackgroundActivity, $minute, $computeIdleMinutes);
            $this->addActivity($intervals['database'], 'human', $hasHumanActivity, $minute, $databaseIdleMinutes);
            $this->addActivity($intervals['database'], 'guest', $hasGuestDatabaseActivity, $minute, $databaseIdleMinutes);
            $this->addActivity($intervals['database'], 'background', $hasBackgroundDatabaseActivity, $minute, $databaseIdleMinutes);
        }

        return [
            'compute' => $this->segments($intervals['compute']),
            'database' => $this->segments($intervals['database']),
        ];
    }

    /**
     * @return array<string, list<array{from: int, to: int}>>
     */
    private function emptyClassIntervals(): array
    {
        return array_fill_keys(self::ACTIVITY_CLASSES, []);
    }

    /**
     * @param  array<string, list<array{from: int, to: int}>>  $classIntervals
     */
    private function addActivity(
        array &$classIntervals,
        string $activityClass,
        bool $hasActivity,
        CarbonImmutable $minute,
        int $idleMinutes,
    ): void {
        if (! $hasActivity) {
            return;
        }

        $from = $minute->getTimestamp();
        $to = $minute->addMinutes($idleMinutes)->getTimestamp();
        $lastIndex = array_key_last($classIntervals[$activityClass]);

        if ($lastIndex === null || $from > $classIntervals[$activityClass][$lastIndex]['to']) {
            $classIntervals[$activityClass][] = compact('from', 'to');

            return;
        }

        $classIntervals[$activityClass][$lastIndex]['to'] = max(
            $classIntervals[$activityClass][$lastIndex]['to'],
            $to,
        );
    }

    /**
     * @param  array<string, list<array{from: int, to: int}>>  $classIntervals
     * @return list<array<string, mixed>>
     */
    private function segments(array $classIntervals): array
    {
        /** @var array<int, array<string, int>> $changes */
        $changes = [];

        foreach ($classIntervals as $activityClass => $intervals) {
            foreach ($intervals as $interval) {
                $changes[$interval['from']][$activityClass] = ($changes[$interval['from']][$activityClass] ?? 0) + 1;
                $changes[$interval['to']][$activityClass] = ($changes[$interval['to']][$activityClass] ?? 0) - 1;
            }
        }

        ksort($changes, SORT_NUMERIC);

        $active = array_fill_keys(self::ACTIVITY_CLASSES, 0);
        $segments = [];
        $segment = null;
        $previousTimestamp = null;

        foreach ($changes as $timestamp => $classChanges) {
            if ($previousTimestamp !== null && $timestamp > $previousTimestamp) {
                $segment = $this->attributeSpan($segment, $active, $previousTimestamp, $timestamp);
            }

            foreach ($classChanges as $activityClass => $change) {
                $active[$activityClass] += $change;
            }

            if ($segment !== null && ! $this->hasActiveClass($active)) {
                $segments[] = $segment;
                $segment = null;
            }

            $previousTimestamp = $timestamp;
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>|null  $segment
     * @param  array<string, int>  $active
     * @return array<string, mixed>|null
     */
    private function attributeSpan(?array $segment, array $active, int $from, int $to): ?array
    {
        if (! $this->hasActiveClass($active)) {
            return $segment;
        }

        $durationMinutes = intdiv($to - $from, 60);
        $segment ??= $this->emptySegment($from);
        $segment['to'] = $this->timestamp($to);
        $segment['duration_minutes'] += $durationMinutes;

        foreach (self::ACTIVITY_CLASSES as $activityClass) {
            if ($active[$activityClass] > 0) {
                $segment['classes'][$activityClass]['sustained_minutes'] += $durationMinutes;
            }
        }

        foreach (self::ACTIVITY_CLASSES as $activityClass) {
            if ($active[$activityClass] > 0) {
                $segment['classes'][$activityClass]['minutes'] += $durationMinutes;

                break;
            }
        }

        return $segment;
    }

    /**
     * @param  array<string, int>  $active
     */
    private function hasActiveClass(array $active): bool
    {
        return array_sum($active) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySegment(int $from): array
    {
        return [
            'from' => $this->timestamp($from),
            'to' => $this->timestamp($from),
            'duration_minutes' => 0,
            'classes' => [
                'human' => ['minutes' => 0, 'sustained_minutes' => 0],
                'guest' => ['minutes' => 0, 'sustained_minutes' => 0],
                'background' => ['minutes' => 0, 'sustained_minutes' => 0],
            ],
        ];
    }

    private function timestamp(int $timestamp): string
    {
        return CarbonImmutable::createFromTimestampUTC($timestamp)->toIso8601ZuluString();
    }
}

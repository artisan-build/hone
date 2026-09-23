<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Support;

use ArtisanBuild\HoneServer\Models\ActivityBucket;
use ArtisanBuild\HoneServer\Models\RequestActivityBucket;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use UnexpectedValueException;

final class GuestTrafficClusterAnalysis
{
    public const IDLE_MINUTES = 5;

    public const MAX_CLUSTERS = 100;

    /**
     * @return array{clusters: list<array<string, mixed>>, total_clusters: int, truncated: bool}
     */
    public function forApp(string $app, CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<int, list<array{key: string, occurred_at: int}>> $guestActivity */
        $guestActivity = [];
        /** @var array<string, array{path: string, user_agent: string|null, asn: int|null, volume: int, awake_minutes: int}> $clusters */
        $clusters = [];

        $guestBuckets = RequestActivityBucket::query()
            ->select(['bucket_minute', 'path', 'user_agent', 'asn', 'hits'])
            ->where('app', $app)
            ->where('actor', 'guest')
            ->whereBetween('bucket_minute', [$from->toIso8601String(), $to->toIso8601String()])
            ->orderBy('bucket_minute')
            ->orderBy('path')
            ->orderBy('user_agent')
            ->orderBy('asn')
            ->cursor();

        foreach ($guestBuckets as $bucket) {
            $bucketMinute = $bucket->getAttribute('bucket_minute');

            if (! $bucketMinute instanceof DateTimeInterface) {
                throw new UnexpectedValueException('Request activity bucket minute must be a date-time value.');
            }

            $timestamp = CarbonImmutable::instance($bucketMinute)->utc()->getTimestamp();
            $path = (string) $bucket->getAttribute('path');
            $userAgent = $bucket->getAttribute('user_agent');
            $asn = $bucket->getAttribute('asn');
            $key = json_encode([$path, $userAgent, $asn], JSON_THROW_ON_ERROR);

            $clusters[$key] ??= [
                'path' => $path,
                'user_agent' => is_string($userAgent) ? $userAgent : null,
                'asn' => is_numeric($asn) ? (int) $asn : null,
                'volume' => 0,
                'awake_minutes' => 0,
            ];
            $clusters[$key]['volume'] += (int) $bucket->getAttribute('hits');
            $guestActivity[$timestamp][] = ['key' => $key, 'occurred_at' => $timestamp];
        }

        if ($guestActivity === []) {
            return ['clusters' => [], 'total_clusters' => 0, 'truncated' => false];
        }

        /** @var array<int, true> $humanActivity */
        $humanActivity = [];
        $humanBuckets = ActivityBucket::query()
            ->select('bucket_minute')
            ->where('app', $app)
            ->where('human_requests', '>', 0)
            ->whereBetween('bucket_minute', [$from->toIso8601String(), $to->toIso8601String()])
            ->orderBy('bucket_minute')
            ->cursor();

        foreach ($humanBuckets as $bucket) {
            $bucketMinute = $bucket->getAttribute('bucket_minute');

            if (! $bucketMinute instanceof DateTimeInterface) {
                throw new UnexpectedValueException('Activity bucket minute must be a date-time value.');
            }

            $humanActivity[CarbonImmutable::instance($bucketMinute)->utc()->getTimestamp()] = true;
        }

        $guestTimestamps = array_keys($guestActivity);
        $scanFrom = min(min($guestTimestamps), $humanActivity === [] ? PHP_INT_MAX : min(array_keys($humanActivity)));
        $scanTo = max($guestTimestamps) + self::IDLE_MINUTES * 60;
        $humanUntil = 0;
        /** @var array<string, array{until: int, last_activity: int}> $activeGuests */
        $activeGuests = [];

        for ($timestamp = $scanFrom; $timestamp < $scanTo; $timestamp += 60) {
            foreach ($activeGuests as $key => $activeGuest) {
                if ($activeGuest['until'] <= $timestamp) {
                    unset($activeGuests[$key]);
                }
            }

            if (isset($humanActivity[$timestamp])) {
                $humanUntil = max($humanUntil, $timestamp + self::IDLE_MINUTES * 60);
            }

            foreach ($guestActivity[$timestamp] ?? [] as $activity) {
                $activeGuests[$activity['key']] = [
                    'until' => $timestamp + self::IDLE_MINUTES * 60,
                    'last_activity' => $activity['occurred_at'],
                ];
            }

            if ($humanUntil > $timestamp) {
                continue;
            }

            $selectedKey = null;
            $selectedTimestamp = -1;

            foreach ($activeGuests as $key => $activeGuest) {
                if (
                    $activeGuest['last_activity'] > $selectedTimestamp
                    || ($activeGuest['last_activity'] === $selectedTimestamp && ($selectedKey === null || $key < $selectedKey))
                ) {
                    $selectedKey = $key;
                    $selectedTimestamp = $activeGuest['last_activity'];
                }
            }

            if ($selectedKey !== null) {
                $clusters[$selectedKey]['awake_minutes']++;
            }
        }

        $clusterRows = array_values($clusters);
        usort($clusterRows, static fn (array $left, array $right): int => [
            -$left['awake_minutes'],
            -$left['volume'],
            $left['path'],
            $left['user_agent'] ?? '',
            $left['asn'] ?? 0,
        ] <=> [
            -$right['awake_minutes'],
            -$right['volume'],
            $right['path'],
            $right['user_agent'] ?? '',
            $right['asn'] ?? 0,
        ]);
        $totalClusters = count($clusterRows);

        return [
            'clusters' => array_slice($clusterRows, 0, self::MAX_CLUSTERS),
            'total_clusters' => $totalClusters,
            'truncated' => $totalClusters > self::MAX_CLUSTERS,
        ];
    }
}

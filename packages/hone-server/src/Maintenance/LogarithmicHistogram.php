<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Maintenance;

final class LogarithmicHistogram
{
    public const RELATIVE_ERROR = 0.01;

    public const BASE = 1 + self::RELATIVE_ERROR;

    /**
     * Return a percentile_cont-compatible estimate from mergeable bucket counts.
     *
     * Every positive value is represented by its bucket's upper bound, so the estimate is never
     * more than one percent above the same percentile calculated from the original values.
     *
     * @param  array<string, int>  $buckets
     */
    public function percentile(array $buckets, float $percentile): ?float
    {
        $bins = [];

        foreach ($buckets as $bucket => $count) {
            if ($count > 0) {
                $bins[] = ['value' => $this->bucketValue($bucket), 'count' => $count];
            }
        }

        if ($bins === []) {
            return null;
        }

        usort($bins, fn (array $left, array $right): int => $left['value'] <=> $right['value']);

        $count = array_sum(array_column($bins, 'count'));
        $position = max(0.0, min(1.0, $percentile)) * ($count - 1);
        $lowerPosition = (int) floor($position);
        $upperPosition = (int) ceil($position);
        $lower = $this->valueAtPosition($bins, $lowerPosition);
        $upper = $this->valueAtPosition($bins, $upperPosition);

        return $lower + (($upper - $lower) * ($position - $lowerPosition));
    }

    private function bucketValue(string $bucket): float
    {
        if ($bucket === 'z') {
            return 0.0;
        }

        $index = (int) substr($bucket, 2);

        return str_starts_with($bucket, 'n:')
            ? -pow(self::BASE, $index)
            : pow(self::BASE, $index + 1);
    }

    /**
     * @param  list<array{value: float, count: int}>  $bins
     */
    private function valueAtPosition(array $bins, int $position): float
    {
        $seen = 0;

        foreach ($bins as $bin) {
            $seen += $bin['count'];

            if ($seen > $position) {
                return $bin['value'];
            }
        }

        return $bins[array_key_last($bins)]['value'];
    }
}

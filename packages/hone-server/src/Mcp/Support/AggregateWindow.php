<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Mcp\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AggregateWindow
{
    /**
     * @var list<string>
     */
    public const METRICS = ['count', 'avg', 'max', 'p95', 'p99'];

    /**
     * @return list<array{normalized_key: string, count: float|null, sample_count: int, avg: float|null, max: float|null, p95: float|null, p99: float|null}>
     */
    public function topOffenders(string $recordType, int $days, ?string $app, ?string $deploy, string $sortMetric, int $limit): array
    {
        $this->ensureMetric($sortMetric);

        return $this->combinedQuery($recordType, $days, $app, $deploy)
            ->addSelect('normalized_key')
            ->groupBy('normalized_key')
            ->orderByRaw($sortMetric.' DESC NULLS LAST')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->formatCombinedRow($row))
            ->all();
    }

    /**
     * @return list<array{normalized_key: string, metric: string, value: float|null, sample_count: int}>
     */
    public function metric(string $recordType, string $metric, ?string $normalizedKey, ?string $app, ?string $deploy, int $days): array
    {
        $this->ensureMetric($metric);

        return $this->combinedQuery($recordType, $days, $app, $deploy)
            ->addSelect('normalized_key')
            ->when($normalizedKey !== null, fn (Builder $query) => $query->where('normalized_key', $normalizedKey))
            ->groupBy('normalized_key')
            ->orderBy('normalized_key')
            ->get()
            ->map(fn (object $row): array => [
                'normalized_key' => (string) $row->normalized_key,
                'metric' => $metric,
                'value' => $this->floatOrNull($row->{$metric}),
                'sample_count' => (int) $row->sample_count,
            ])
            ->all();
    }

    /**
     * @return list<array{deploy: string, latest_bucket_date: string, metric: string, value: float|null, sample_count: int}>
     */
    public function acrossDeploys(string $recordType, string $normalizedKey, string $metric, ?string $app, int $deploysLimit): array
    {
        $this->ensureMetric($metric);

        return $this->combinedQuery($recordType, null, $app, null)
            ->addSelect('deploy')
            ->selectRaw('max(bucket_date) as latest_bucket_date')
            ->where('normalized_key', $normalizedKey)
            ->whereNotNull('deploy')
            ->groupBy('deploy')
            ->orderByDesc('latest_bucket_date')
            ->limit($deploysLimit)
            ->get()
            ->map(fn (object $row): array => [
                'deploy' => (string) $row->deploy,
                'latest_bucket_date' => (string) $row->latest_bucket_date,
                'metric' => $metric,
                'value' => $this->floatOrNull($row->{$metric}),
                'sample_count' => (int) $row->sample_count,
            ])
            ->all();
    }

    private function combinedQuery(string $recordType, ?int $days, ?string $app, ?string $deploy): Builder
    {
        $query = DB::connection('hone')->table('aggregates')
            ->selectRaw("sum(value) filter (where metric = 'count') as count")
            ->selectRaw("coalesce(sum(value) filter (where metric = 'count'), 0) as sample_count")
            ->selectRaw("sum(value * sample_count) filter (where metric = 'avg') / nullif(sum(sample_count) filter (where metric = 'avg'), 0) as avg")
            ->selectRaw("max(value) filter (where metric = 'max') as max")
            // Percentiles cannot be averaged across daily aggregate buckets; use the worst daily percentile in the window.
            ->selectRaw("max(value) filter (where metric = 'p95') as p95")
            ->selectRaw("max(value) filter (where metric = 'p99') as p99")
            ->where('record_type', $recordType)
            // Inclusive: days=N covers bucket_date from today-N through today, so today's partial bucket is included.
            ->when($days !== null, fn (Builder $query) => $query->whereDate('bucket_date', '>=', Carbon::now('UTC')->subDays($days)->toDateString()))
            ->when($app !== null, fn (Builder $query) => $query->where('app', $app))
            ->when($deploy !== null, fn (Builder $query) => $query->where('deploy', $deploy));

        return $query;
    }

    /**
     * @return array{normalized_key: string, count: float|null, sample_count: int, avg: float|null, max: float|null, p95: float|null, p99: float|null}
     */
    private function formatCombinedRow(object $row): array
    {
        return [
            'normalized_key' => (string) $row->normalized_key,
            'count' => $this->floatOrNull($row->count),
            'sample_count' => (int) $row->sample_count,
            'avg' => $this->floatOrNull($row->avg),
            'max' => $this->floatOrNull($row->max),
            'p95' => $this->floatOrNull($row->p95),
            'p99' => $this->floatOrNull($row->p99),
        ];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function ensureMetric(string $metric): void
    {
        if (! in_array($metric, self::METRICS, true)) {
            throw new InvalidArgumentException('Unsupported aggregate metric ['.$metric.'].');
        }
    }
}

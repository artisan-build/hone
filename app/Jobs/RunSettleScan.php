<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

/**
 * TEMPORARY proof job: runs the vendored `settle` analysis engine against a public
 * git repo entirely inside hone's Cloud queue-worker context. Results are stashed in
 * the (database-backed) cache so they can be read back via `cloud tinker`. Delete after.
 */
class RunSettleScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    private const CACHE_KEY = 'settle:scan:result';

    private const STATUS_KEY = 'settle:scan:status';

    public function __construct(
        public string $repoUrl = 'https://github.com/laravel/framework.git',
        public ?string $now = null,
    ) {}

    public function handle(): void
    {
        // Idempotency guard: if the message is redelivered (e.g. visibility timeout
        // elapses mid-run) the second delivery exits immediately instead of double-running.
        $lock = Cache::store('database')->lock('settle:scan:lock', 1800);
        if (! $lock->get()) {
            $this->status(['phase' => 'skipped-locked']);

            return;
        }

        $now = $this->now ?: date('Y-m-d');
        $tmp = sys_get_temp_dir();
        $clone = $tmp.'/settle_scan_'.bin2hex(random_bytes(4));
        $out = $tmp.'/settle_out_'.bin2hex(random_bytes(4));
        $engine = base_path('settle-engine');

        $context = [
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'hostname' => gethostname(),
            'queue_connection' => config('queue.default'),
            'cache_store' => config('cache.default'),
            'is_cli' => PHP_SAPI === 'cli',
        ];
        $diskStart = (int) round(@disk_free_space($tmp) / 1048576);
        $this->status(['phase' => 'starting', 'context' => $context, 'started_at' => date('c')]);

        $startedAt = microtime(true);
        $errors = [];

        $cloneProc = $this->run([
            'git', 'clone', '--filter=blob:none', '--no-checkout', $this->repoUrl, $clone,
        ], $tmp, 600);
        $this->status(['phase' => 'cloned', 'clone_ok' => $cloneProc['ok']]);
        if (! $cloneProc['ok']) {
            $errors[] = ['step' => 'clone', 'stderr' => $cloneProc['stderr'], 'exit' => $cloneProc['exit']];
        }

        $diskAfterClone = (int) round(@disk_free_space($tmp) / 1048576);

        // Single combined run: --backtest computes and writes score.json, score_trajectory.json,
        // score_coverage.json AND score_backtest.json — a superset of the plain run.
        // Run through a tiny runpy wrapper that prints peak RSS (self,children) to stderr on exit.
        $wrapper = <<<'PY'
import sys, runpy, resource, atexit
def _peak():
    sys.stderr.write("SETTLE_PEAK_RSS_KB=%d,%d\n" % (
        resource.getrusage(resource.RUSAGE_SELF).ru_maxrss,
        resource.getrusage(resource.RUSAGE_CHILDREN).ru_maxrss))
atexit.register(_peak)
sys.argv = sys.argv[1:]
runpy.run_module("settle.score", run_name="__main__")
PY;
        $scanProc = $this->run([
            'python3', '-c', $wrapper,
            'settle.score',
            '--repo', $clone,
            '--now', $now,
            '--out', $out,
            '--backtest',
            '--jobs', '2',
        ], $engine, 1700, ['PYTHONPATH' => $engine, 'GIT_TERMINAL_PROMPT' => '0']);
        $this->status(['phase' => 'scanned', 'scan_ok' => $scanProc['ok']]);
        if (! $scanProc['ok']) {
            $errors[] = ['step' => 'scan', 'stderr' => $scanProc['stderr'], 'exit' => $scanProc['exit']];
        }

        $diskAfterScan = (int) round(@disk_free_space($tmp) / 1048576);
        $cloneSizeMb = $this->dirSizeMb($clone);

        // Peak RSS (KB on Linux) printed by the runpy wrapper: "SETTLE_PEAK_RSS_KB=<self>,<children>".
        $peakRssSelfKb = null;
        $peakRssChildrenKb = null;
        if (preg_match('/SETTLE_PEAK_RSS_KB=(\d+),(\d+)/', $scanProc['stderr'], $m)) {
            $peakRssSelfKb = (int) $m[1];
            $peakRssChildrenKb = (int) $m[2];
        }
        $df = $this->run(['df', '-h', $tmp], $tmp, 30);

        $outputs = [];
        foreach (['score.json', 'score_trajectory.json', 'score_coverage.json', 'score_backtest.json'] as $file) {
            $path = $out.'/'.$file;
            $outputs[$file] = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        }

        // Cleanup temp artifacts.
        $this->run(['rm', '-rf', $clone], $tmp, 120);
        $this->run(['rm', '-rf', $out], $tmp, 120);

        $payload = [
            'context' => $context,
            'repo' => $this->repoUrl,
            'now' => $now,
            'runtime_seconds' => round(microtime(true) - $startedAt, 1),
            'disk' => [
                'free_start_mb' => $diskStart,
                'free_after_clone_mb' => $diskAfterClone,
                'free_after_scan_mb' => $diskAfterScan,
                'peak_used_mb' => $diskStart - min($diskAfterClone, $diskAfterScan),
                'clone_dir_size_mb' => $cloneSizeMb,
                'df_tmp' => trim($df['stdout']),
            ],
            'memory' => [
                'peak_rss_self_mb' => $peakRssSelfKb !== null ? round($peakRssSelfKb / 1024, 1) : null,
                'peak_rss_children_mb' => $peakRssChildrenKb !== null ? round($peakRssChildrenKb / 1024, 1) : null,
                'note' => 'ru_maxrss; KB on Linux. self=python aggregator, children=largest git child.',
            ],
            'clone_ok' => $cloneProc['ok'],
            'scan_ok' => $scanProc['ok'],
            'scan_exit' => $scanProc['exit'],
            'scan_stdout' => $scanProc['stdout'],
            'scan_stderr_tail' => $this->tail($scanProc['stderr'], 4000),
            'outputs' => $outputs,
            'errors' => $errors,
            'completed_at' => date('c'),
        ];

        Cache::store('database')->put(self::CACHE_KEY, $payload, now()->addHours(6));
        $this->status(['phase' => 'done', 'scan_ok' => $scanProc['ok'], 'runtime_seconds' => $payload['runtime_seconds']]);
        optional($lock)->release();
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     * @return array{ok: bool, exit: int|null, stdout: string, stderr: string}
     */
    private function run(array $command, string $cwd, int $timeout, array $env = []): array
    {
        $process = new Process($command, $cwd, $env ?: null, null, $timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'exit' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    private function dirSizeMb(string $path): ?int
    {
        if (! is_dir($path)) {
            return null;
        }
        $du = $this->run(['du', '-sm', $path], sys_get_temp_dir(), 120);

        return $du['ok'] ? (int) trim(explode("\t", $du['stdout'])[0]) : null;
    }

    private function tail(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? '...'.substr($text, -$bytes) : $text;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function status(array $data): void
    {
        Cache::store('database')->put(self::STATUS_KEY, $data + ['at' => date('c')], now()->addHours(6));
    }
}

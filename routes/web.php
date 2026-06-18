<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Hone',
    'status' => 'ok',
]))->name('home');

// routes/web.php — TEMPORARY settle env probe; delete after.
Route::get('/__settle-envcheck-9f3a2b', function () {
    $run = function (string $cmd): array {
        if (!function_exists('exec')) return ['ok' => false, 'error' => 'exec disabled'];
        $out = []; $code = null; @exec($cmd.' 2>&1', $out, $code);
        return ['ok' => $code === 0, 'code' => $code, 'output' => trim(implode("\n", $out))];
    };
    $tmp = sys_get_temp_dir();
    $clonePath = $tmp.'/settle_envcheck_'.bin2hex(random_bytes(4));
    $clone = $run('git clone --filter=blob:none --no-checkout https://github.com/octocat/Hello-World.git '.escapeshellarg($clonePath));
    if (is_dir($clonePath)) $run('rm -rf '.escapeshellarg($clonePath));
    return response()->json([
        'php_version' => PHP_VERSION,
        'exec_enabled' => function_exists('exec'),
        'proc_open_enabled' => function_exists('proc_open'),
        'disabled_functions' => array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))))),
        'python3' => $run('python3 --version'),
        'python' => $run('python --version'),
        'python3_12' => $run('python3.12 --version'),
        'git' => $run('git --version'),
        'blobless_clone' => $clone,
        'tmp_dir' => $tmp,
        'tmp_writable' => is_writable($tmp),
        'disk_free_mb' => round(@disk_free_space($tmp) / 1048576),
    ]);
});

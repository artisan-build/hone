<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 | With no HONE_DB_* configured — the fork-and-deploy default — telemetry lives in the
 | application's own database, so a single Cloud Postgres needs no extra configuration.
 */

it('resolves the hone connection to the app database when HONE_DB_* is unset', function (): void {
    expect(env('HONE_DB_HOST'))->toBeNull()
        ->and(env('HONE_DB_DATABASE'))->toBeNull();

    expect(config('database.connections.hone'))
        ->toBe(config('database.connections.'.config('database.default')));
});

it('points the hone connection at the same physical database as the default connection', function (): void {
    expect(DB::connection('hone')->getDatabaseName())
        ->toBe(DB::connection()->getDatabaseName());
});

it('keeps telemetry and app tables side by side on one connection', function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $tables = DB::connection('hone')->getSchemaBuilder()->getTableListing();
    $tables = array_map(fn (string $table): string => str_contains($table, '.')
        ? substr($table, (int) strrpos($table, '.') + 1)
        : $table, $tables);

    expect($tables)->toContain('raw_events', 'samples', 'aggregates', 'api_tokens');
});

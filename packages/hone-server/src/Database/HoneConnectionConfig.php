<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Database;

/**
 * Resolves the database configuration Hone's telemetry (`hone`) connection runs on.
 *
 * Telemetry shares the application's own database by default so a fork-and-deploy on a
 * single Postgres works with no extra configuration. Setting any `HONE_DB_*` value opts
 * into a separate telemetry database: those values override the inherited ones, and the
 * rest of the application's connection configuration is carried across untouched.
 */
final class HoneConnectionConfig
{
    /**
     * @param  array<string, mixed>  $default  the resolved default connection's configuration
     * @param  array<string, mixed>  $overrides  the `HONE_DB_*` values; nulls mean "not set"
     * @return array<string, mixed>
     */
    public static function resolve(array $default, array $overrides): array
    {
        $overrides = array_filter($overrides, fn (mixed $value): bool => $value !== null);

        if ($overrides === []) {
            return $default;
        }

        $config = array_merge($default, $overrides);

        /*
         | Hone's telemetry schema is Postgres-only (jsonb columns, jsonb_typeof() at rollup),
         | so an explicitly configured telemetry database is always a pgsql connection.
         */
        $config['driver'] = 'pgsql';
        $config['charset'] ??= 'utf8';
        $config['prefix'] ??= '';
        $config['search_path'] ??= 'public';
        $config['timezone'] ??= 'UTC';

        /*
         | An inherited connection URL would be parsed back over the explicit host and
         | database below it, silently ignoring the telemetry database that was asked for.
         */
        if (! array_key_exists('url', $overrides)) {
            unset($config['url']);
        }

        return $config;
    }
}

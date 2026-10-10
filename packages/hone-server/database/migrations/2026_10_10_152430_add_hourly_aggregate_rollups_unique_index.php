<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::connection('hone')->statement(
            'CREATE UNIQUE INDEX CONCURRENTLY hourly_aggregate_rollups_unique ON hourly_aggregate_rollups (app, record_type, normalized_key, deploy, bucket_hour) NULLS NOT DISTINCT'
        );
        DB::connection('hone')->statement(
            'CREATE INDEX CONCURRENTLY hourly_aggregate_rollups_bucket_hour_index ON hourly_aggregate_rollups (bucket_hour)'
        );
    }

    public function down(): void
    {
        DB::connection('hone')->statement('DROP INDEX CONCURRENTLY IF EXISTS hourly_aggregate_rollups_bucket_hour_index');
        DB::connection('hone')->statement('DROP INDEX CONCURRENTLY IF EXISTS hourly_aggregate_rollups_unique');
    }
};

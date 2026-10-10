<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public bool $withinTransaction = false;

    public function up(): void
    {
        DB::connection('hone')->statement(
            'CREATE UNIQUE INDEX CONCURRENTLY hourly_aggregate_rollups_unique ON hourly_aggregate_rollups (app, record_type, normalized_key, deploy, bucket_hour) NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        DB::connection('hone')->statement('DROP INDEX CONCURRENTLY IF EXISTS hourly_aggregate_rollups_unique');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('ingest_counters', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->string('deploy')->nullable();
            $table->unsignedBigInteger('overflow_dropped_records')->default(0);
            $table->unsignedBigInteger('failed_delivery_records')->default(0);
            $table->timestampsTz();
        });

        DB::connection('hone')->statement(
            'CREATE UNIQUE INDEX ingest_counters_app_deploy_unique ON ingest_counters (app, deploy) NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('ingest_counters');
    }
};

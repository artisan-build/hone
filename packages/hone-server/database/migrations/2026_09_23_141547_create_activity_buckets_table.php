<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('activity_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->timestampTz('bucket_minute');
            $table->unsignedBigInteger('human_requests')->default(0);
            $table->unsignedBigInteger('guest_requests')->default(0);
            $table->unsignedBigInteger('guest_requests_with_queries')->default(0);
            $table->unsignedBigInteger('scheduled_runs_with_queries')->default(0);
            $table->unsignedBigInteger('scheduled_runs_without_queries')->default(0);
            $table->unsignedBigInteger('jobs_with_queries')->default(0);
            $table->unsignedBigInteger('jobs_without_queries')->default(0);
            $table->timestampsTz();

            $table->unique(['app', 'bucket_minute']);
            $table->index('bucket_minute');
        });

        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->timestampTz('activity_bucketed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->dropColumn('activity_bucketed_at');
        });

        Schema::connection('hone')->dropIfExists('activity_buckets');
    }
};

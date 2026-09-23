<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('background_activity_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->timestampTz('bucket_minute');
            $table->string('activity_type');
            $table->string('identity');
            $table->unsignedBigInteger('runs_with_queries')->default(0);
            $table->timestampsTz();

            $table->unique(
                ['app', 'bucket_minute', 'activity_type', 'identity'],
                'background_activity_bucket_unique',
            );
            $table->index('bucket_minute', 'background_activity_bucket_minute_index');
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('background_activity_buckets');
    }
};

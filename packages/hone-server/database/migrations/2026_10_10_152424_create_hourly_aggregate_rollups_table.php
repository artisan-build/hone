<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('hourly_aggregate_rollups', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->string('record_type');
            $table->string('normalized_key');
            $table->string('deploy')->nullable();
            $table->timestampTz('bucket_hour');
            $table->unsignedBigInteger('sample_count');
            $table->unsignedBigInteger('numeric_count');
            $table->double('numeric_sum')->nullable();
            $table->double('max_value')->nullable();
            $table->jsonb('histogram');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('hourly_aggregate_rollups');
    }
};

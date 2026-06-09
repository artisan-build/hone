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
        Schema::connection('hone')->create('aggregates', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->string('record_type');
            $table->string('normalized_key');
            $table->string('deploy')->nullable();
            $table->date('bucket_date');
            $table->string('metric');
            $table->double('value');
            $table->unsignedBigInteger('sample_count');
            $table->timestampsTz();

            $table->index(['app', 'record_type', 'metric', 'bucket_date']);
        });

        DB::connection('hone')->statement(
            'CREATE UNIQUE INDEX aggregates_rollup_unique ON aggregates (app, record_type, normalized_key, deploy, bucket_date, metric) NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('aggregates');
    }
};

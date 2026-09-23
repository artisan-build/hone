<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->text('request_path')->nullable();
            $table->string('request_host')->nullable();
        });

        Schema::connection('hone')->create('request_activity_buckets', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->timestampTz('bucket_minute');
            $table->string('actor');
            $table->text('path');
            $table->string('host')->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('asn')->nullable();
            $table->boolean('ran_queries')->nullable();
            $table->boolean('sets_cookie')->nullable();
            $table->text('cache_control')->nullable();
            $table->text('vary')->nullable();
            $table->unsignedBigInteger('hits')->default(0);
            $table->string('dimensions_hash', 32);
            $table->timestamps();

            $table->unique(
                ['app', 'bucket_minute', 'dimensions_hash'],
                'request_activity_bucket_unique',
            );
            $table->index(
                ['app', 'actor', 'bucket_minute'],
                'request_activity_bucket_app_actor_minute_index',
            );
            $table->index('bucket_minute', 'request_activity_bucket_minute_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('request_activity_buckets');

        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->dropColumn(['request_path', 'request_host']);
        });
    }
};

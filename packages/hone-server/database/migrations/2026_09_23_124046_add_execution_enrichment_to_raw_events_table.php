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
            $table->string('actor')->nullable()->index();
            $table->boolean('ran_queries')->nullable();
            $table->jsonb('response')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->unsignedBigInteger('asn')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->dropColumn([
                'actor',
                'ran_queries',
                'response',
                'user_agent',
                'client_ip',
                'asn',
            ]);
        });
    }
};

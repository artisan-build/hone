<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('raw_events', function (Blueprint $table): void {
            $table->id();
            $table->string('app')->index();
            $table->string('record_type');
            $table->string('deploy')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->string('normalized_key');
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['app', 'record_type', 'occurred_at']);
            $table->index(['app', 'record_type', 'normalized_key']);
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('raw_events');
    }
};

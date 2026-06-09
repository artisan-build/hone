<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('samples', function (Blueprint $table): void {
            $table->id();
            $table->string('app');
            $table->string('record_type');
            $table->string('normalized_key');
            $table->string('deploy')->nullable();
            $table->timestampTz('occurred_at');
            $table->jsonb('payload');
            $table->double('value')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['app', 'record_type', 'normalized_key', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('samples');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->double('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('hone')->table('raw_events', function (Blueprint $table): void {
            $table->dropColumn('duration_ms');
        });
    }
};

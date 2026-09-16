<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Maintenance\MaintenanceMarkers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('hone')->create('maintenance_markers', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestampTz('updated_at');
        });

        app(MaintenanceMarkers::class)->seedRollupWatermarkFromLegacyRollup();
    }

    public function down(): void
    {
        Schema::connection('hone')->dropIfExists('maintenance_markers');
    }
};

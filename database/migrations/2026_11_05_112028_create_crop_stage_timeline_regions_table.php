<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_stage_timeline_regions', function (Blueprint $table): void {
            $table->foreignId('crop_stage_timeline_id')->constrained('crop_stage_timelines')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->primary(['crop_stage_timeline_id', 'region_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_stage_timeline_regions');
    }
};

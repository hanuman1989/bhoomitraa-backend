<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_stage_timelines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_stage_id')->constrained('crop_stages')->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained('crop_varieties')->restrictOnDelete();
            $table->foreignId('season_id')->nullable()->constrained('seasons')->restrictOnDelete();
            $table->string('region_scope', 20);
            $table->unsignedInteger('days_after_sowing_min');
            $table->unsignedInteger('days_after_sowing_max');
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index(['crop_stage_id', 'season_id', 'is_active'], 'idx_stage_timelines_stage_season_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_stage_timelines');
    }
};

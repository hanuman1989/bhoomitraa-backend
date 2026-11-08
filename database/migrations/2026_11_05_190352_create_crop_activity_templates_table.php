<?php

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
        Schema::create('crop_activity_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained('crops')->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained('crop_varieties')->restrictOnDelete();
            $table->foreignId('category_id')->constrained('activity_categories')->restrictOnDelete();
            $table->foreignId('crop_stage_id')->nullable()->constrained('crop_stages')->restrictOnDelete();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
            $table->string('schedule_type', 30);
            $table->integer('start_offset_days')->nullable();
            $table->integer('end_offset_days')->nullable();
            $table->string('region_scope', 20);
            $table->string('priority', 20)->default('medium');
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['crop_id', 'season_id', 'is_active'], 'idx_activity_templates_crop_season_active');
            $table->index(['category_id', 'is_active'], 'idx_activity_templates_category_active');
            $table->index(['crop_stage_id', 'crop_variety_id'], 'idx_activity_templates_stage_variety');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_activity_templates');
    }
};

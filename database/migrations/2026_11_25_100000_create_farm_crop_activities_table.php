<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One farmer activity per applicable template per crop cycle. Titles, instructions,
     * category, flags and smart actions are read from the template, never copied here.
     */
    public function up(): void
    {
        Schema::create('farm_crop_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crop_activity_template_id')->constrained()->restrictOnDelete();
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('pending');
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['farm_crop_cycle_id', 'crop_activity_template_id'], 'uq_farm_crop_activities_cycle_template');
            $table->index(['farm_crop_cycle_id', 'start_date'], 'idx_farm_crop_activities_cycle_start');
            $table->index(['farm_crop_cycle_id', 'status'], 'idx_farm_crop_activities_cycle_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_crop_activities');
    }
};

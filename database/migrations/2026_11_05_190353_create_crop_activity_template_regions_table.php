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
        Schema::create('crop_activity_template_regions', function (Blueprint $table): void {
            $table->unsignedBigInteger('crop_activity_template_id');
            $table->unsignedBigInteger('region_id');
            $table->primary(['crop_activity_template_id', 'region_id'], 'pk_activity_template_regions');
            $table->foreign('crop_activity_template_id', 'fk_activity_template_regions_template')
                ->references('id')->on('crop_activity_templates')->cascadeOnDelete();
            $table->foreign('region_id', 'fk_activity_template_regions_region')
                ->references('id')->on('regions')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_activity_template_regions');
    }
};

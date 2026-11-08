<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_recommendation_regions', function (Blueprint $table): void {
            $table->foreignId('fertilizer_recommendation_id');
            $table->foreign('fertilizer_recommendation_id', 'fk_fert_rec_regions_parent')->references('id')->on('fertilizer_recommendations')->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->primary(['fertilizer_recommendation_id', 'region_id'], 'pk_fert_rec_regions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_recommendation_regions');
    }
};

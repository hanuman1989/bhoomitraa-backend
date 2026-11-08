<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_seed_rate_regions', function (Blueprint $table): void {
            $table->foreignId('crop_seed_rate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->primary(['crop_seed_rate_id', 'region_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_seed_rate_regions');
    }
};

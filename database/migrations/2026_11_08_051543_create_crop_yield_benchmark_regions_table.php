<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_yield_benchmark_regions', function (Blueprint $table): void {
            $table->foreignId('crop_yield_benchmark_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->primary(['crop_yield_benchmark_id', 'region_id'], 'pk_crop_yield_benchmark_regions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_yield_benchmark_regions');
    }
};

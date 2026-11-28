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
        Schema::create('sowing_method_crops', function (Blueprint $table): void {
            $table->foreignId('sowing_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->primary(['sowing_method_id', 'crop_id']);
            $table->index(['crop_id', 'sowing_method_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sowing_method_crops');
    }
};

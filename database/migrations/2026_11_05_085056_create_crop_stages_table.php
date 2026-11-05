<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_stages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained('crops')->restrictOnDelete();
            $table->string('code', 50);
            $table->unsignedSmallInteger('sequence');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['crop_id', 'code'], 'uq_crop_stages_crop_code');
            $table->unique(['crop_id', 'sequence'], 'uq_crop_stages_crop_sequence');
            $table->index(['crop_id', 'is_active'], 'idx_crop_stages_crop_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_stages');
    }
};

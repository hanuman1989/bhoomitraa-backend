<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_stage_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_stage_id')->constrained('crop_stages')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['crop_stage_id', 'locale'], 'uq_crop_stage_translations_locale');
            $table->index(['locale', 'name'], 'idx_crop_stage_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_stage_translations');
    }
};

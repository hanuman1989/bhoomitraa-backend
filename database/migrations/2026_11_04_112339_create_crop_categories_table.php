<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique('slug', 'uq_crop_categories_slug');
            $table->index('is_active', 'idx_crop_categories_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('crop_category_id');
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('crop_category_id', 'fk_crop_category_translations_category')
                ->references('id')->on('crop_categories')->cascadeOnDelete();
            $table->unique(['crop_category_id', 'locale'], 'uq_crop_category_translations_locale');
            $table->index(['locale', 'name'], 'idx_crop_category_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_category_translations');
    }
};

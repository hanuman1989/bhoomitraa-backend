<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_field_layout_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_field_layout_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('important_considerations')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['crop_field_layout_id', 'locale'], 'field_layout_translation_locale_unique');
            $table->index(['locale', 'title']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_field_layout_translations');
    }
};

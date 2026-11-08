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
        Schema::create('activity_category_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activity_category_id');
            $table->string('locale', 10);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('activity_category_id', 'fk_activity_category_translations_category')
                ->references('id')->on('activity_categories')->cascadeOnDelete();
            $table->unique(['activity_category_id', 'locale'], 'uq_activity_category_locale');
            $table->index(['locale', 'name'], 'idx_activity_category_translations_locale_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_category_translations');
    }
};

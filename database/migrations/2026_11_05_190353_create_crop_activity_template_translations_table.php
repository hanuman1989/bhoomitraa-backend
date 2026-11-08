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
        Schema::create('crop_activity_template_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('crop_activity_template_id');
            $table->string('locale', 10);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->text('instructions')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->foreign('crop_activity_template_id', 'fk_activity_template_translations_template')
                ->references('id')->on('crop_activity_templates')->cascadeOnDelete();
            $table->unique(['crop_activity_template_id', 'locale'], 'uq_activity_template_locale');
            $table->index(['locale', 'title'], 'idx_activity_template_translations_locale_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_activity_template_translations');
    }
};

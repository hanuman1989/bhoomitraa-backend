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
        Schema::create('crop_variety_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crop_variety_id');
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('region_notes', 255)->nullable();
            $table->timestamps(6);

            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('crop_variety_id', 'fk_crop_variety_translations_variety')
                ->references('id')->on('crop_varieties')->cascadeOnDelete();
            $table->unique(['crop_variety_id', 'locale'], 'uq_crop_variety_translations_locale');
            $table->index(['locale', 'name'], 'idx_crop_variety_translations_locale_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_variety_translations');
    }
};

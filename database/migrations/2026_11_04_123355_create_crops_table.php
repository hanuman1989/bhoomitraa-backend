<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id');
            $table->string('slug');
            $table->string('scientific_name')->nullable();
            $table->string('crop_code', 50)->nullable();
            $table->string('image', 500)->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('ai_diagnosis_enabled')->default(false);
            $table->timestamps();

            /** Match the required non-null DATETIME(6) columns in the catalogue schema. */
            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('category_id', 'fk_crops_category')->references('id')->on('crop_categories')->restrictOnDelete();
            $table->unique('slug', 'uq_crops_slug');
            $table->unique('crop_code', 'uq_crops_code');
            $table->index(['category_id', 'is_active'], 'idx_crops_category_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crops');
    }
};

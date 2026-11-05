<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('crop_id');
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            /** Match the crop master's non-null DATETIME(6) timestamps. */
            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('crop_id', 'fk_crop_translations_crop')->references('id')->on('crops')->cascadeOnDelete();
            $table->unique(['crop_id', 'locale'], 'uq_crop_translations_locale');
            $table->index(['locale', 'name'], 'idx_crop_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_translations');
    }
};

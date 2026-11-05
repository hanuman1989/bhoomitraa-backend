<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('region_id');
            $table->string('locale', 10);
            $table->string('name', 150);
            $table->timestamps(6);

            /** Match the master table's required non-null DATETIME(6) timestamps. */
            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('region_id', 'fk_region_translations_region')->references('id')->on('regions')->cascadeOnDelete();
            $table->unique(['region_id', 'locale'], 'uq_region_translations_locale');
            $table->index(['locale', 'name'], 'idx_region_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_translations');
    }
};

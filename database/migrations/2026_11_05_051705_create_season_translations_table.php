<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('season_id');
            $table->string('locale', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('season_id', 'fk_season_translations_season')
                ->references('id')->on('seasons')->cascadeOnDelete();
            $table->unique(['season_id', 'locale'], 'uq_season_translations_locale');
            $table->index(['locale', 'name'], 'idx_season_translations_locale_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_translations');
    }
};

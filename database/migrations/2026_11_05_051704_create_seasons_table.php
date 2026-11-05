<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('slug');
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->unique('slug', 'uq_seasons_slug');
            $table->index('is_active', 'idx_seasons_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};

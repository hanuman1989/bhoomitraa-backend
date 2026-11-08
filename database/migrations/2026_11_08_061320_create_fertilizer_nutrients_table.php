<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_nutrients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fertilizer_id')->constrained()->cascadeOnDelete();
            $table->string('nutrient_code', 20);
            $table->decimal('content_value', 12, 4);
            $table->string('content_unit_code', 20);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['fertilizer_id', 'nutrient_code', 'content_unit_code'], 'uq_fertilizer_nutrient_unit');
        });
        $expression = "content_value >= 0 AND (content_unit_code NOT IN ('percent_ww', 'percent_wv') OR content_value <= 100)";
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fertilizer_nutrients ADD CONSTRAINT chk_fertilizer_nutrient_value CHECK ('.$expression.')');
        } elseif (DB::getDriverName() === 'sqlite') {
            $newExpression = str_replace(['content_value', 'content_unit_code'], ['NEW.content_value', 'NEW.content_unit_code'], $expression);
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER chk_fertilizer_nutrient_value_'.strtolower($operation).' BEFORE '.$operation.' ON fertilizer_nutrients FOR EACH ROW WHEN NOT ('.$newExpression.') BEGIN SELECT RAISE(ABORT, \'Invalid nutrient content.\'); END');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_nutrients');
    }
};

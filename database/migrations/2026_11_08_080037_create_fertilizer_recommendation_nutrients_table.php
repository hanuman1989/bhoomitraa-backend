<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_recommendation_nutrients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fertilizer_recommendation_id');
            $table->foreign('fertilizer_recommendation_id', 'fk_fert_rec_nutrients_parent')->references('id')->on('fertilizer_recommendations')->cascadeOnDelete();
            $table->string('nutrient_code', 20);
            $table->decimal('recommended_quantity', 14, 4);
            $table->string('unit_code', 20);
            $table->string('area_unit_code', 20);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['fertilizer_recommendation_id', 'nutrient_code'], 'uq_fert_rec_nutrient');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fertilizer_recommendation_nutrients ADD CONSTRAINT chk_fert_rec_quantity CHECK (recommended_quantity > 0)');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER chk_fert_rec_quantity_'.strtolower($operation).' BEFORE '.$operation.' ON fertilizer_recommendation_nutrients FOR EACH ROW WHEN NEW.recommended_quantity <= 0 BEGIN SELECT RAISE(ABORT, \'Nutrient quantity must be positive.\'); END');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_recommendation_nutrients');
    }
};

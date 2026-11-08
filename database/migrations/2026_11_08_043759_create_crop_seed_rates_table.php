<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_seed_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('seed_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('material_type', 40);
            $table->string('sowing_method', 40)->nullable();
            $table->string('irrigation_condition', 30)->nullable();
            $table->string('rate_basis', 30)->default('per_area');
            $table->decimal('rate_min', 14, 4)->nullable();
            $table->decimal('rate_recommended', 14, 4);
            $table->decimal('rate_max', 14, 4)->nullable();
            $table->string('rate_unit_code', 20);
            $table->string('area_unit_code', 20);
            $table->decimal('spacing_row_cm', 10, 2)->nullable();
            $table->decimal('spacing_plant_cm', 10, 2)->nullable();
            $table->string('region_scope', 20);
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['crop_id', 'crop_variety_id', 'season_id', 'is_active'], 'idx_crop_seed_rates_lookup');
            $table->index('region_scope');
        });

        $checks = [
            'recommended' => 'rate_recommended > 0',
            'minimum' => 'rate_min IS NULL OR (rate_min >= 0 AND rate_min <= rate_recommended)',
            'maximum' => 'rate_max IS NULL OR rate_max >= rate_recommended',
            'row_spacing' => 'spacing_row_cm IS NULL OR spacing_row_cm > 0',
            'plant_spacing' => 'spacing_plant_cm IS NULL OR spacing_plant_cm > 0',
        ];
        foreach ($checks as $name => $expression) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE crop_seed_rates ADD CONSTRAINT chk_crop_seed_rates_'.$name.' CHECK ('.$expression.')');
            } elseif (DB::getDriverName() === 'sqlite') {
                $newExpression = preg_replace('/\\b(rate_recommended|rate_min|rate_max|spacing_row_cm|spacing_plant_cm)\\b/', 'NEW.$1', $expression);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_crop_seed_rates_'.$name.'_'.strtolower($operation).' BEFORE '.$operation.' ON crop_seed_rates FOR EACH ROW WHEN NOT ('.$newExpression.') BEGIN SELECT RAISE(ABORT, \'Invalid seed recommendation rate or spacing.\'); END');
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_seed_rates');
    }
};

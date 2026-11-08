<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_yield_benchmarks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('region_scope', 20);
            $table->decimal('yield_min_kg_ha', 12, 3)->nullable();
            $table->decimal('yield_avg_kg_ha', 12, 3)->nullable();
            $table->decimal('yield_max_kg_ha', 12, 3)->nullable();
            $table->string('benchmark_type', 30);
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->unsignedSmallInteger('reference_year')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['crop_id', 'crop_variety_id', 'season_id', 'is_active'], 'idx_crop_yield_benchmarks_lookup');
            $table->index(['benchmark_type', 'reference_year', 'is_active'], 'idx_crop_yield_benchmarks_evidence');
            $table->index('region_scope');
        });
        $checks = [
            'average' => 'yield_avg_kg_ha IS NULL OR yield_avg_kg_ha > 0',
            'minimum' => 'yield_min_kg_ha IS NULL OR (yield_min_kg_ha >= 0 AND (yield_avg_kg_ha IS NULL OR yield_min_kg_ha <= yield_avg_kg_ha))',
            'maximum' => 'yield_max_kg_ha IS NULL OR (yield_max_kg_ha >= 0 AND (yield_avg_kg_ha IS NULL OR yield_max_kg_ha >= yield_avg_kg_ha))',
            'reference_year' => 'reference_year IS NULL OR reference_year BETWEEN 1000 AND 9999',
        ];
        foreach ($checks as $name => $expression) {
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                DB::statement('ALTER TABLE crop_yield_benchmarks ADD CONSTRAINT chk_crop_yield_benchmarks_'.$name.' CHECK ('.$expression.')');
            } elseif (DB::getDriverName() === 'sqlite') {
                $newExpression = preg_replace('/\\b(yield_avg_kg_ha|yield_min_kg_ha|yield_max_kg_ha|reference_year)\\b/', 'NEW.$1', $expression);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_crop_yield_benchmarks_'.$name.'_'.strtolower($operation).' BEFORE '.$operation.' ON crop_yield_benchmarks FOR EACH ROW WHEN NOT ('.$newExpression.') BEGIN SELECT RAISE(ABORT, \'Invalid benchmark yield or reference year.\'); END');
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_yield_benchmarks');
    }
};

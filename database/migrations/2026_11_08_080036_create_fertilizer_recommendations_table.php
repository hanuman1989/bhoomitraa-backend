<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('recommendation_type', 40);
            $table->string('irrigation_condition', 30)->nullable();
            $table->string('soil_condition', 40)->nullable();
            $table->string('application_method', 40)->nullable();
            $table->string('reference_code', 100)->nullable()->unique('uq_fert_recommendations_reference');
            $table->string('region_scope', 20);
            $table->foreignId('source_id')->constrained('knowledge_sources')->restrictOnDelete();
            $table->unsignedSmallInteger('reference_year')->nullable();
            $table->text('basal_dose_notes')->nullable();
            $table->text('top_dressing_notes')->nullable();
            $table->text('general_advisory')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['crop_id', 'crop_variety_id', 'season_id', 'is_active'], 'idx_fert_recommendations_lookup');
            $table->index('region_scope');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fertilizer_recommendations ADD CONSTRAINT chk_fert_rec_year CHECK (reference_year IS NULL OR reference_year BETWEEN 1000 AND 9999)');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER chk_fert_rec_year_'.strtolower($operation).' BEFORE '.$operation.' ON fertilizer_recommendations FOR EACH ROW WHEN NEW.reference_year IS NOT NULL AND NEW.reference_year NOT BETWEEN 1000 AND 9999 BEGIN SELECT RAISE(ABORT, \'Invalid reference year.\'); END');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_recommendations');
    }
};

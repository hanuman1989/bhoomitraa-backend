<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('farm_crop_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('seed_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('seed_lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('seed_source', 30)->nullable();
            $table->decimal('seed_quantity', 14, 4)->nullable();
            $table->string('seed_quantity_unit', 20)->nullable();
            $table->text('seed_notes')->nullable();
            $table->date('sowing_date')->nullable();
            $table->string('sowing_method', 40)->nullable();
            $table->decimal('cultivated_area', 18, 8)->nullable();
            $table->string('area_unit', 20)->nullable();
            if (DB::getDriverName() === 'sqlite') {
                $table->text('boundary')->nullable();
            } else {
                $table->geometry('boundary', subtype: 'multipolygon', srid: DB::connection()->isMaria() ? null : 4326)->nullable();
            }
            $table->string('boundary_source', 20)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            foreach (['expected_harvest_date', 'expected_harvest_date_min', 'expected_harvest_date_max', 'actual_harvest_date'] as $field) {
                $table->date($field)->nullable();
            }
            $table->decimal('actual_yield', 14, 4)->nullable();
            $table->string('yield_unit', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('completion_reason', 50)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->text('completion_notes')->nullable();
            $table->unsignedTinyInteger('completed_step')->default(1);
            $table->json('wizard_progress');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('finalized_at', 6)->nullable();
            $table->json('planning_snapshot')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('creation_key', 64);
            $table->string('creation_payload_hash', 64);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['created_by', 'creation_key']);
            $table->index(['farm_id', 'status']);
        });
        Schema::create('farm_crop_soil_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_cycle_id')->constrained()->cascadeOnDelete();
            foreach (['fertile_capacity', 'soil_moisture', 'drainage_condition', 'water_availability'] as $field) {
                $table->string($field, 30)->nullable();
            }
            $table->string('assessment_source', 30)->default('farmer');
            $table->dateTime('assessed_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        Schema::create('soil_tests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_id')->constrained()->restrictOnDelete();
            $table->foreignId('farm_crop_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('test_date');
            $table->date('sample_collected_at')->nullable();
            $table->string('laboratory_name', 200)->nullable();
            $table->string('report_number', 100)->nullable();
            $table->decimal('sample_depth_cm', 8, 2)->nullable();
            foreach (['soil_ph', 'electrical_conductivity', 'organic_carbon', 'nitrogen', 'phosphorus', 'potassium', 'sulphur', 'zinc', 'iron', 'copper', 'manganese', 'boron'] as $field) {
                $table->decimal($field, 14, 4)->nullable();
            }
            $table->json('measurement_units')->nullable();
            $table->string('report_file', 500)->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('verified_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        Schema::create('farm_crop_fertilizer_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fertilizer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('custom_name', 200)->nullable();
            $table->foreignId('fertilizer_recommendation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('quantity_mode', 20)->default('custom');
            $table->decimal('recommended_quantity', 14, 4)->nullable();
            $table->decimal('planned_quantity', 14, 4)->nullable();
            $table->string('quantity_unit_code', 20);
            $table->foreignId('fertilizer_price_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('package_quantity', 14, 4)->nullable();
            $table->string('package_unit_code', 20)->nullable();
            $table->unsignedInteger('package_count')->nullable();
            $table->decimal('package_price', 16, 4)->nullable();
            $table->decimal('estimated_cost', 16, 4)->nullable();
            $table->char('currency_code', 3)->default('INR');
            $table->json('calculation_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['farm_crop_cycle_id', 'fertilizer_id'], 'farm_crop_fertilizer_product_unique');
        });
        Schema::create('farm_crop_yield_estimates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('yield_benchmark_id')->constrained('crop_yield_benchmarks')->restrictOnDelete();
            $table->string('estimate_type', 30)->default('benchmark');
            foreach (['estimated_min_yield', 'estimated_avg_yield', 'estimated_max_yield'] as $field) {
                $table->decimal($field, 18, 4)->nullable();
            }
            $table->string('yield_unit', 20);
            $table->decimal('cultivated_area', 18, 8);
            $table->string('area_unit', 20);
            $table->dateTime('estimated_at', 6);
            $table->string('model_version', 50)->nullable();
            $table->json('input_snapshot');
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 6);
        });
        Schema::create('farm_crop_edit_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_cycle_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft_data');
            $table->json('wizard_progress');
            $table->unsignedInteger('base_revision');
            $table->unsignedInteger('revision');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE farm_crop_cycles ADD CONSTRAINT chk_crop_cycle_status CHECK (status IN ('draft','active','completed','cancelled')), ADD CONSTRAINT chk_crop_cycle_area CHECK (cultivated_area IS NULL OR cultivated_area > 0), ADD CONSTRAINT chk_crop_cycle_srid CHECK (boundary IS NULL OR ST_SRID(boundary) = 4326)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('farm_crop_edit_drafts');
        Schema::dropIfExists('farm_crop_yield_estimates');
        Schema::dropIfExists('farm_crop_fertilizer_plans');
        Schema::dropIfExists('soil_tests');
        Schema::dropIfExists('farm_crop_soil_assessments');
        Schema::dropIfExists('farm_crop_cycles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('farms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('ownership_type', 30)->nullable();
            $table->decimal('area', 12, 3);
            $table->string('area_unit', 20);
            $table->string('area_source', 30)->default('government_record');
            $table->decimal('actual_area', 12, 3)->nullable();
            $table->string('actual_area_unit', 20)->nullable();
            $table->decimal('actual_area_sqm', 18, 6)->nullable();
            foreach (['region_id', 'state_id', 'district_id', 'tehsil_id'] as $field) {
                $table->foreignId($field)->nullable()->constrained('regions')->nullOnDelete();
            }
            $table->string('village', 150)->nullable();
            $table->string('address', 500)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            if (DB::getDriverName() === 'sqlite') {
                $table->text('boundary')->nullable();
            } else {
                $isMaria = DB::connection()->isMaria();
                $table->geometry('boundary', subtype: 'multipolygon', srid: $isMaria ? null : 4326)->nullable();
            }
            $table->string('boundary_source', 20)->nullable();
            $table->decimal('gps_accuracy_m', 8, 2)->nullable();
            foreach (['khasra_number', 'khata_number', 'patwari_halka_number'] as $field) {
                $table->string($field, 100)->nullable();
            }
            foreach (['soil_type', 'land_type', 'farmland_type', 'irrigation_source'] as $field) {
                $table->string($field, 30)->nullable();
            }
            $table->string('water_availability', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('creation_key', 64)->nullable();
            $table->string('creation_payload_hash', 64)->nullable();
            $table->dateTime('creation_expires_at', 6)->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['user_id', 'is_active']);
            $table->unique(['user_id', 'creation_key']);
        });

        $checks = [
            'area' => 'area > 0',
            'area_source' => "area_source = 'government_record'",
            'actual_area' => 'actual_area IS NULL OR actual_area > 0',
            'actual_area_sqm' => 'actual_area_sqm IS NULL OR actual_area_sqm > 0',
            'latitude' => 'latitude IS NULL OR latitude BETWEEN -90 AND 90',
            'longitude' => 'longitude IS NULL OR longitude BETWEEN -180 AND 180',
            'coordinates' => '(latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL)',
            'boundary' => "(boundary IS NULL AND boundary_source IS NULL AND actual_area IS NULL AND actual_area_unit IS NULL AND actual_area_sqm IS NULL AND gps_accuracy_m IS NULL) OR (boundary IS NOT NULL AND boundary_source IS NOT NULL AND boundary_source IN ('manual', 'gps') AND actual_area IS NOT NULL AND actual_area_unit IS NOT NULL AND actual_area_sqm IS NOT NULL)",
            'gps_accuracy' => "gps_accuracy_m IS NULL OR (boundary_source = 'gps' AND gps_accuracy_m >= 0)",
        ];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $checks['srid'] = 'boundary IS NULL OR ST_SRID(boundary) = 4326';
            foreach ($checks as $name => $expression) {
                DB::statement('ALTER TABLE farms ADD CONSTRAINT chk_farms_'.$name.' CHECK ('.$expression.')');
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach ($checks as $name => $expression) {
                $expression = preg_replace('/\b(area|area_source|actual_area|actual_area_unit|actual_area_sqm|latitude|longitude|boundary|boundary_source|gps_accuracy_m)\b/', 'NEW.$1', $expression);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_farms_'.$name.'_'.strtolower($operation).' BEFORE '.$operation.' ON farms FOR EACH ROW WHEN NOT ('.$expression.') BEGIN SELECT RAISE(ABORT, \'Invalid farm area, boundary or coordinates.\'); END');
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farms');
    }
};

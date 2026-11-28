<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('irrigation_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->string('name', 150);
            $table->string('asset_type', 20);
            $table->string('source_type', 30)->nullable();
            $table->string('pipeline_type', 30)->nullable();
            // The target database is MySQL 8.4, where this column is declared with SRID 4326.
            // The current schema snapshot runs on MariaDB 10.4, so there the column has no SRID
            // clause and chk_irrigation_assets_srid below enforces SRID 4326. A later compatibility
            // migration can add the SRID clause to the column. SQLite test databases store GeoJSON text.
            if (DB::getDriverName() === 'sqlite') {
                $table->text('geometry')->nullable();
            } else {
                $table->geometry('geometry', srid: DB::connection()->isMaria() ? null : 4326)->nullable();
            }
            $table->decimal('length_meters', 12, 2)->nullable();
            $table->decimal('diameter_mm', 8, 2)->nullable();
            $table->decimal('depth_meters', 10, 2)->nullable();
            $table->string('water_availability', 20)->nullable();
            $table->string('ownership_type', 20)->nullable();
            $table->string('status', 30)->default('operational');
            $table->text('notes')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->softDeletesDatetime('deleted_at', 6);
            $table->index(['user_id', 'deleted_at', 'asset_type'], 'idx_irrigation_user_assets');
            $table->index(['user_id', 'deleted_at', 'status'], 'idx_irrigation_user_status');
            // Added after the indexes so the foreign key uses idx_irrigation_user_assets.
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        // Subtype, availability and ownership keys are validated in PHP, so a new key needs no migration.
        $checks = [
            'asset_type' => "asset_type IN ('source', 'pipeline')",
            'status' => "status IN ('operational', 'needs_maintenance', 'out_of_service', 'inactive')",
            'dimensions' => '(length_meters IS NULL OR length_meters > 0) AND (diameter_mm IS NULL OR diameter_mm > 0)'
                .' AND (depth_meters IS NULL OR depth_meters > 0)',
            'category' => "(asset_type = 'source' AND source_type IS NOT NULL AND pipeline_type IS NULL"
                .' AND length_meters IS NULL AND diameter_mm IS NULL)'
                ." OR (asset_type = 'pipeline' AND pipeline_type IS NOT NULL AND source_type IS NULL"
                .' AND depth_meters IS NULL AND water_availability IS NULL)',
        ];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $checks['srid'] = '`geometry` IS NULL OR ST_SRID(`geometry`) = 4326';
            foreach ($checks as $name => $expression) {
                DB::statement('ALTER TABLE irrigation_assets ADD CONSTRAINT chk_irrigation_assets_'.$name.' CHECK ('.$expression.')');
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach ($checks as $name => $expression) {
                $expression = preg_replace('/\b(asset_type|source_type|pipeline_type|length_meters|diameter_mm|depth_meters|water_availability|status)\b/', 'NEW.$1', $expression);
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_irrigation_assets_'.$name.'_'.strtolower($operation).' BEFORE '.$operation.' ON irrigation_assets FOR EACH ROW WHEN NOT ('.$expression.') BEGIN SELECT RAISE(ABORT, \'Invalid irrigation asset.\'); END');
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('irrigation_assets');
    }
};

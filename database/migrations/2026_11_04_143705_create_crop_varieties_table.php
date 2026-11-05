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
        Schema::create('crop_varieties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('crop_id');
            $table->string('slug');
            $table->string('variety_code', 50)->nullable();
            $table->unsignedInteger('maturity_days_min')->nullable();
            $table->unsignedInteger('maturity_days_max')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps(6);

            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('crop_id', 'fk_crop_varieties_crop')->references('id')->on('crops')->restrictOnDelete();
            $table->foreign('source_id', 'fk_crop_varieties_source')->references('id')->on('knowledge_sources')->restrictOnDelete();
            $table->unique(['crop_id', 'slug'], 'uq_crop_varieties_crop_slug');
            $table->unique(['crop_id', 'variety_code'], 'uq_crop_varieties_crop_code');
            $table->index(['crop_id', 'is_active'], 'idx_crop_varieties_crop_active');
            $table->index('source_id', 'idx_crop_varieties_source');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE crop_varieties ADD CONSTRAINT chk_crop_varieties_maturity CHECK (maturity_days_min IS NULL OR maturity_days_max IS NULL OR maturity_days_min <= maturity_days_max)');
        } elseif (DB::getDriverName() === 'sqlite') {
            /** SQLite cannot add a table CHECK after creation, so triggers enforce the same invariant in tests. */
            DB::unprepared("CREATE TRIGGER chk_crop_varieties_maturity_insert BEFORE INSERT ON crop_varieties FOR EACH ROW WHEN NEW.maturity_days_min IS NOT NULL AND NEW.maturity_days_max IS NOT NULL AND NEW.maturity_days_min > NEW.maturity_days_max BEGIN SELECT RAISE(ABORT, 'Invalid crop variety maturity range.'); END");
            DB::unprepared("CREATE TRIGGER chk_crop_varieties_maturity_update BEFORE UPDATE OF maturity_days_min, maturity_days_max ON crop_varieties FOR EACH ROW WHEN NEW.maturity_days_min IS NOT NULL AND NEW.maturity_days_max IS NOT NULL AND NEW.maturity_days_min > NEW.maturity_days_max BEGIN SELECT RAISE(ABORT, 'Invalid crop variety maturity range.'); END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_varieties');
    }
};

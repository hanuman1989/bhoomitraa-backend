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
        Schema::create('seed_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_id')->constrained()->restrictOnDelete();
            $table->string('lot_number', 100);
            $table->string('seed_class', 40)->nullable();
            $table->date('production_date')->nullable();
            $table->date('test_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->decimal('germination_percentage', 5, 2)->nullable();
            $table->decimal('physical_purity_percentage', 5, 2)->nullable();
            $table->decimal('genetic_purity_percentage', 5, 2)->nullable();
            $table->string('seed_treatment_status', 30)->nullable();
            $table->string('certification_number', 100)->nullable();
            $table->string('certification_body', 200)->nullable();
            $table->string('report_file', 500)->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('verified_at', 6)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['seed_id', 'lot_number'], 'uq_seed_lots_seed_number');
            $table->index(['seed_id', 'valid_until', 'is_active'], 'idx_seed_lots_validity');
            $table->index(['verification_status', 'is_active'], 'idx_seed_lots_verification');
            $table->index('certification_number', 'idx_seed_lots_certification');
        });
        foreach (['germination_percentage', 'physical_purity_percentage', 'genetic_purity_percentage'] as $field) {
            if (DB::getDriverName() === 'mysql') {
                DB::statement('ALTER TABLE seed_lots ADD CONSTRAINT chk_seed_lots_'.$field.' CHECK ('.$field.' IS NULL OR '.$field.' BETWEEN 0 AND 100)');
            } elseif (DB::getDriverName() === 'sqlite') {
                /** SQLite triggers mirror the checks because it cannot add a table CHECK after creation. */
                foreach (['INSERT', 'UPDATE'] as $operation) {
                    DB::unprepared('CREATE TRIGGER chk_seed_lots_'.$field.'_'.strtolower($operation).' BEFORE '.$operation.' ON seed_lots FOR EACH ROW WHEN NEW.'.$field.' IS NOT NULL AND (NEW.'.$field.' < 0 OR NEW.'.$field.' > 100) BEGIN SELECT RAISE(ABORT, \'Quality percentages must be between 0 and 100.\'); END');
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seed_lots');
    }
};

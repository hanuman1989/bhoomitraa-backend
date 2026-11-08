<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizers', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 220)->unique();
            $table->string('fertilizer_code', 100)->nullable()->unique();
            $table->string('fertilizer_type', 40);
            $table->string('form', 30);
            $table->string('brand_name', 150)->nullable();
            $table->string('manufacturer_name', 200)->nullable();
            $table->string('grade', 50)->nullable();
            $table->string('default_unit_code', 20);
            $table->decimal('reference_pack_quantity', 14, 4)->nullable();
            $table->string('reference_pack_unit_code', 20)->nullable();
            $table->string('application_method', 40)->nullable();
            $table->text('composition_notes')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->restrictOnDelete();
            $table->string('verification_status', 20)->default('pending')->index();
            $table->boolean('is_active')->default(false);
            $table->boolean('requested_is_active')->default(false);
            $table->string('setup_status', 20)->default('draft');
            $table->unsignedTinyInteger('completed_step')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['setup_status', 'is_active']);
            $table->index(['fertilizer_type', 'form']);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fertilizers ADD CONSTRAINT chk_fertilizer_pack CHECK (reference_pack_quantity IS NULL OR reference_pack_quantity > 0)');
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER chk_fertilizer_pack_'.strtolower($operation).' BEFORE '.$operation.' ON fertilizers FOR EACH ROW WHEN NEW.reference_pack_quantity IS NOT NULL AND NEW.reference_pack_quantity <= 0 BEGIN SELECT RAISE(ABORT, \'Pack quantity must be positive.\'); END');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizers');
    }
};

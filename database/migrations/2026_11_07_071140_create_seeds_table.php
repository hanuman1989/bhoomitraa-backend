<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seeds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->foreignId('crop_variety_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('slug', 220)->unique();
            $table->string('sku', 100)->nullable()->unique();
            $table->string('producer_name', 200)->nullable();
            $table->string('brand_name', 150)->nullable();
            $table->string('seed_type', 40)->nullable();
            $table->string('material_type', 40);
            $table->string('propagation_method', 40)->nullable();
            $table->string('seed_class', 40)->nullable();
            $table->string('treatment_status', 30);
            $table->boolean('is_f1_hybrid')->nullable();
            $table->boolean('is_organic')->nullable();
            $table->boolean('is_pelleted')->nullable();
            $table->string('default_unit_code', 20);
            $table->decimal('reference_pack_quantity', 14, 4)->nullable();
            $table->string('reference_pack_unit_code', 20)->nullable();
            $table->decimal('seeds_per_gram', 12, 3)->nullable();
            $table->decimal('germination_reference_pct', 5, 2)->nullable();
            $table->decimal('purity_reference_pct', 5, 2)->nullable();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->restrictOnDelete();
            $table->string('verification_status', 20)->default('pending')->index();
            $table->boolean('is_active')->default(false);
            $table->boolean('requested_is_active')->default(false);
            $table->string('setup_status', 20)->default('draft');
            $table->unsignedTinyInteger('completed_step')->default(1);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['setup_status', 'is_active']);
            $table->index('material_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seeds');
    }
};

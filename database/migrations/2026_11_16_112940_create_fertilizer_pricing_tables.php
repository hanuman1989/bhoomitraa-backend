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
        Schema::create('fertilizer_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fertilizer_id')->constrained()->restrictOnDelete();
            $table->string('seller_name', 200)->nullable();
            $table->string('price_type', 30);
            $table->decimal('package_quantity', 14, 4);
            $table->string('package_unit_code', 20);
            $table->char('currency_code', 3)->default('INR');
            $table->decimal('package_price', 16, 4);
            $table->string('region_scope', 20);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('knowledge_sources')->restrictOnDelete();
            $table->dateTime('last_verified_at', 6)->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['fertilizer_id', 'is_active', 'effective_from']);
        });
        Schema::create('fertilizer_price_regions', function (Blueprint $table): void {
            $table->foreignId('fertilizer_price_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->primary(['fertilizer_price_id', 'region_id']);
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE fertilizer_prices ADD CONSTRAINT chk_fertilizer_prices_values CHECK (package_quantity > 0 AND package_price >= 0 AND (effective_to IS NULL OR effective_to >= effective_from) AND region_scope IN ('all', 'specific'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fertilizer_price_regions');
        Schema::dropIfExists('fertilizer_prices');
    }
};

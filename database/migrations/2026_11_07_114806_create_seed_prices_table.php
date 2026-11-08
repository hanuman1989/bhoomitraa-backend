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
        Schema::create('seed_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_id')->constrained()->restrictOnDelete();
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
            $table->index(['seed_id', 'price_type', 'is_active'], 'idx_seed_prices_seed_type_active');
            $table->index('region_scope');
            $table->index(['effective_from', 'effective_to'], 'idx_seed_prices_effective_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seed_prices');
    }
};

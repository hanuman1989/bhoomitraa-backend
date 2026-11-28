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
        Schema::create('sowing_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('slug', 180)->unique();
            $table->string('establishment_type', 30);
            $table->string('crop_scope', 20)->default('specific');
            $table->boolean('supports_row_spacing')->nullable()->default(null);
            $table->boolean('supports_plant_spacing')->nullable()->default(null);
            $table->boolean('supports_field_layout')->nullable()->default(null);
            $table->string('machinery_requirement', 20)->default('varies');
            $table->string('labour_requirement', 20)->default('varies');
            $table->string('cost_level', 20)->default('varies');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['is_active', 'sort_order']);
            $table->index(['establishment_type', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sowing_methods');
    }
};

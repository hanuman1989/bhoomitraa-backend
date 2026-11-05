<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 50)->nullable();
            $table->string('region_type', 30);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('parent_id', 'fk_regions_parent')->references('id')->on('regions')->restrictOnDelete();
            $table->index(['parent_id', 'region_type'], 'idx_regions_parent_type');
            $table->index(['region_type', 'is_active'], 'idx_regions_type_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};

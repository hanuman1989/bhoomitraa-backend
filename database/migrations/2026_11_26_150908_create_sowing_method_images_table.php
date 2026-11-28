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
        Schema::create('sowing_method_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sowing_method_id')->constrained()->cascadeOnDelete();
            $table->string('image', 500);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['sowing_method_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sowing_method_images');
    }
};

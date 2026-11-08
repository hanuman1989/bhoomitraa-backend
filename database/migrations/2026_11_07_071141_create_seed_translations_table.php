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
        Schema::create('seed_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('planting_instructions')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['seed_id', 'locale']);
            $table->index(['locale', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seed_translations');
    }
};

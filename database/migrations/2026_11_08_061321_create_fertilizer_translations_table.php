<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fertilizer_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->text('usage_instructions')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['fertilizer_id', 'locale']);
            $table->index(['locale', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_translations');
    }
};

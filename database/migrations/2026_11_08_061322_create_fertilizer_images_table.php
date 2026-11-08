<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fertilizer_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fertilizer_id')->constrained()->cascadeOnDelete();
            $table->string('image_path', 500);
            $table->string('alt_text', 255)->nullable();
            $table->unsignedInteger('sort_order');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['fertilizer_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fertilizer_images');
    }
};

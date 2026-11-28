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
        Schema::create('sowing_method_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sowing_method_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 200);
            $table->string('short_description', 500)->nullable();
            $table->text('description')->nullable();
            $table->text('advantages')->nullable();
            $table->text('disadvantages')->nullable();
            $table->text('suitable_conditions')->nullable();
            $table->text('resource_requirements')->nullable();
            $table->text('economic_considerations')->nullable();
            $table->text('important_considerations')->nullable();
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->unique(['sowing_method_id', 'locale']);
            $table->index(['locale', 'title']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sowing_method_translations');
    }
};

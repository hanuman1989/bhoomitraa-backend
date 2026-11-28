<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_field_layouts', function (Blueprint $table): void {
            $table->id();
            $table->string('layout_type', 50);
            $table->string('crop_scope', 20)->default('specific');
            $table->foreignId('crop_variety_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('sowing_method_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('farming_type', 40)->nullable();
            $table->string('growing_system', 40)->nullable();
            $table->unsignedSmallInteger('configuration_version')->default(1);
            $table->json('configuration');
            $table->text('reference_note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['is_active', 'sort_order']);
            $table->index(['layout_type', 'is_active']);
            $table->index(['farming_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_field_layouts');
    }
};

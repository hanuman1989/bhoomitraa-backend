<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crop_field_layout_crops', function (Blueprint $table): void {
            $table->foreignId('crop_field_layout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crop_id')->constrained()->restrictOnDelete();
            $table->primary(['crop_field_layout_id', 'crop_id']);
            $table->index(['crop_id', 'crop_field_layout_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crop_field_layout_crops');
    }
};

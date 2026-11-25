<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plain-text farmer notes recorded against one crop activity.
     */
    public function up(): void
    {
        Schema::create('farm_crop_activity_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('farm_crop_activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('note');
            $table->date('note_date');
            $table->dateTime('created_at', 6);
            $table->dateTime('updated_at', 6);
            $table->index(['farm_crop_activity_id', 'note_date'], 'idx_farm_crop_activity_notes_activity_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_crop_activity_notes');
    }
};

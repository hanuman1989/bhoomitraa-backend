<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_source_attachments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('knowledge_source_id');
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->string('file_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->timestamps(6);

            /** Match the approved non-null DATETIME(6) timestamps. */
            foreach ($table->getColumns() as $column) {
                if (in_array($column->name, ['created_at', 'updated_at'], true)) {
                    $column->type = 'dateTime';
                    $column->nullable = false;
                }
            }

            $table->foreign('knowledge_source_id', 'fk_ks_attachments_source')->references('id')->on('knowledge_sources')->cascadeOnDelete();
            $table->index('knowledge_source_id', 'idx_ks_attachments_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_source_attachments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crop_variety_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('crop_variety_id');
            $table->unsignedBigInteger('source_id');
            $table->timestamps(6);

            $table->foreign('crop_variety_id', 'fk_crop_variety_sources_crop_variety')
                ->references('id')->on('crop_varieties')->cascadeOnDelete();
            $table->foreign('source_id', 'fk_crop_variety_sources_source')
                ->references('id')->on('knowledge_sources')->restrictOnDelete();
            $table->unique(['crop_variety_id', 'source_id'], 'uq_crop_variety_sources_crop_variety_source');
        });

        $legacyAssignments = DB::table('crop_varieties')
            ->whereNotNull('source_id')
            ->select('id', 'source_id')
            ->orderBy('id')
            ->get();

        foreach ($legacyAssignments as $assignment) {
            DB::table('crop_variety_sources')->insertOrIgnore([
                'crop_variety_id' => $assignment->id,
                'source_id' => $assignment->source_id,
                'created_at' => now()->format('Y-m-d H:i:s.u'),
                'updated_at' => now()->format('Y-m-d H:i:s.u'),
            ]);
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crop_variety_sources');
    }
};

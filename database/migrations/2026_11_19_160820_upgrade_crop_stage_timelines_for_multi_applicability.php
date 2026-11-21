<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('crop_stage_timelines', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_variety_id')->nullable()->change();
                $table->unsignedBigInteger('season_id')->nullable()->change();
                $table->unsignedBigInteger('source_id')->nullable()->change();
            });
        }

        Schema::table('crop_stage_timelines', function (Blueprint $table): void {
            if (! Schema::hasColumn('crop_stage_timelines', 'variety_scope')) {
                $table->string('variety_scope', 20)->default('all');
            }
            if (! Schema::hasColumn('crop_stage_timelines', 'season_scope')) {
                $table->string('season_scope', 20)->default('all');
            }
        });

        if (! Schema::hasTable('crop_stage_timeline_varieties')) {
            Schema::create('crop_stage_timeline_varieties', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_stage_timeline_id');
                $table->unsignedBigInteger('crop_variety_id');
                $table->primary(['crop_stage_timeline_id', 'crop_variety_id'], 'pk_stage_timeline_varieties');
                $table->foreign('crop_stage_timeline_id', 'fk_stage_timeline_varieties_timeline')
                    ->references('id')->on('crop_stage_timelines')->cascadeOnDelete();
                $table->foreign('crop_variety_id', 'fk_stage_timeline_varieties_variety')
                    ->references('id')->on('crop_varieties')->restrictOnDelete();
                $table->index(['crop_variety_id', 'crop_stage_timeline_id'], 'idx_stage_timeline_varieties_reverse');
            });
        }
        if (! Schema::hasTable('crop_stage_timeline_seasons')) {
            Schema::create('crop_stage_timeline_seasons', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_stage_timeline_id');
                $table->unsignedBigInteger('season_id');
                $table->primary(['crop_stage_timeline_id', 'season_id'], 'pk_stage_timeline_seasons');
                $table->foreign('crop_stage_timeline_id', 'fk_stage_timeline_seasons_timeline')
                    ->references('id')->on('crop_stage_timelines')->cascadeOnDelete();
                $table->foreign('season_id', 'fk_stage_timeline_seasons_season')
                    ->references('id')->on('seasons')->restrictOnDelete();
                $table->index(['season_id', 'crop_stage_timeline_id'], 'idx_stage_timeline_seasons_reverse');
            });
        }
        if (! Schema::hasTable('crop_stage_timeline_sources')) {
            Schema::create('crop_stage_timeline_sources', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_stage_timeline_id');
                $table->unsignedBigInteger('source_id');
                $table->primary(['crop_stage_timeline_id', 'source_id'], 'pk_stage_timeline_sources');
                $table->foreign('crop_stage_timeline_id', 'fk_stage_timeline_sources_timeline')
                    ->references('id')->on('crop_stage_timelines')->cascadeOnDelete();
                $table->foreign('source_id', 'fk_stage_timeline_sources_source')
                    ->references('id')->on('knowledge_sources')->restrictOnDelete();
                $table->index(['source_id', 'crop_stage_timeline_id'], 'idx_stage_timeline_sources_reverse');
            });
        }

        if (Schema::hasColumn('crop_stage_timelines', 'crop_variety_id')
            && Schema::hasColumn('crop_stage_timelines', 'season_id')
            && Schema::hasColumn('crop_stage_timelines', 'source_id')) {
            DB::table('crop_stage_timelines')
                ->select(['id', 'crop_variety_id', 'season_id', 'source_id'])
                ->orderBy('id')
                ->chunkById(500, function ($timelines): void {
                    foreach ($timelines as $timeline) {
                        DB::table('crop_stage_timelines')->where('id', $timeline->id)->update([
                            'variety_scope' => $timeline->crop_variety_id === null ? 'all' : 'specific',
                            'season_scope' => $timeline->season_id === null ? 'all' : 'specific',
                        ]);
                        if ($timeline->crop_variety_id !== null) {
                            DB::table('crop_stage_timeline_varieties')->insertOrIgnore([
                                'crop_stage_timeline_id' => $timeline->id,
                                'crop_variety_id' => $timeline->crop_variety_id,
                            ]);
                        }
                        if ($timeline->season_id !== null) {
                            DB::table('crop_stage_timeline_seasons')->insertOrIgnore([
                                'crop_stage_timeline_id' => $timeline->id,
                                'season_id' => $timeline->season_id,
                            ]);
                        }
                        if ($timeline->source_id !== null) {
                            DB::table('crop_stage_timeline_sources')->insertOrIgnore([
                                'crop_stage_timeline_id' => $timeline->id,
                                'source_id' => $timeline->source_id,
                            ]);
                        }
                    }
                });

            if (DB::getDriverName() !== 'sqlite') {
                Schema::table('crop_stage_timelines', function (Blueprint $table): void {
                    $foreignKeys = collect(Schema::getForeignKeys('crop_stage_timelines'));
                    foreach ($foreignKeys as $foreignKey) {
                        $columns = $foreignKey['columns'] ?? [];
                        if (count(array_intersect($columns, ['crop_variety_id', 'season_id', 'source_id'])) > 0) {
                            $table->dropForeign($foreignKey['name']);
                        }
                    }
                });
            }
        }

        Schema::table('crop_stage_timelines', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('crop_stage_timelines'))->pluck('name')->all();
            if (! in_array('idx_stage_timelines_stage_active', $indexes, true)) {
                $table->index(['crop_stage_id', 'is_active'], 'idx_stage_timelines_stage_active');
            }
            if (in_array('idx_stage_timelines_stage_season_active', $indexes, true)) {
                $table->dropIndex('idx_stage_timelines_stage_season_active');
            }
            $legacyColumns = array_values(array_filter(
                ['crop_variety_id', 'season_id', 'source_id'],
                fn (string $column): bool => Schema::hasColumn('crop_stage_timelines', $column),
            ));
            if ($legacyColumns !== [] && DB::getDriverName() !== 'sqlite') {
                $table->dropColumn($legacyColumns);
            }
        });
    }

    /**
     * Unsafe rollback is intentionally prevented because multiple relationships
     * cannot be represented by the legacy singular schema.
     */
    public function down(): void
    {
        throw new RuntimeException('Lossless rollback is not supported. Restore from deployment backup.');
    }
};

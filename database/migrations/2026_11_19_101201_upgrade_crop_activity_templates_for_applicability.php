<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $negativeOffsets = DB::table('crop_activity_templates')
            ->where(fn ($query) => $query->where('start_offset_days', '<', 0)->orWhere('end_offset_days', '<', 0))
            ->exists();
        if ($negativeOffsets) {
            throw new RuntimeException('Migration aborted: negative crop activity template offsets exist. Review them before retrying.');
        }

        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $columns = [
                'variety_scope', 'season_scope', 'schedule_basis', 'activity_type',
                'allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes',
                'allow_attachments', 'allow_rescheduling', 'smart_actions',
                'trigger_type', 'trigger_config',
            ];
            foreach ($columns as $column) {
                if (! Schema::hasColumn('crop_activity_templates', $column)) {
                    if ($column === 'smart_actions' || $column === 'trigger_config') {
                        $table->json($column)->nullable();
                    } elseif (str_starts_with($column, 'allow_')) {
                        $default = in_array($column, ['allow_status_update', 'allow_notes'], true);
                        $table->boolean($column)->default($default);
                    } elseif ($column === 'variety_scope' || $column === 'season_scope') {
                        $table->string($column, 20)->default('all');
                    } elseif ($column === 'schedule_basis') {
                        $table->string($column, 30)->default('fixed_offsets');
                    } elseif ($column === 'activity_type') {
                        $table->string($column, 30)->default('task');
                    } elseif ($column === 'trigger_type') {
                        $table->string($column, 30)->default('scheduled');
                    }
                }
            }
        });

        if (! Schema::hasTable('crop_activity_template_varieties')) {
            Schema::create('crop_activity_template_varieties', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_activity_template_id');
                $table->unsignedBigInteger('crop_variety_id');
                $table->primary(['crop_activity_template_id', 'crop_variety_id'], 'pk_activity_template_varieties');
                $table->foreign('crop_activity_template_id', 'fk_activity_template_varieties_template')
                    ->references('id')->on('crop_activity_templates')->cascadeOnDelete();
                $table->foreign('crop_variety_id', 'fk_activity_template_varieties_variety')
                    ->references('id')->on('crop_varieties')->restrictOnDelete();
                $table->index(['crop_variety_id', 'crop_activity_template_id'], 'idx_activity_template_varieties_reverse');
            });
        }

        if (! Schema::hasTable('crop_activity_template_seasons')) {
            Schema::create('crop_activity_template_seasons', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_activity_template_id');
                $table->unsignedBigInteger('season_id');
                $table->primary(['crop_activity_template_id', 'season_id'], 'pk_activity_template_seasons');
                $table->foreign('crop_activity_template_id', 'fk_activity_template_seasons_template')
                    ->references('id')->on('crop_activity_templates')->cascadeOnDelete();
                $table->foreign('season_id', 'fk_activity_template_seasons_season')
                    ->references('id')->on('seasons')->restrictOnDelete();
                $table->index(['season_id', 'crop_activity_template_id'], 'idx_activity_template_seasons_reverse');
            });
        }

        if (! Schema::hasTable('crop_activity_template_sources')) {
            Schema::create('crop_activity_template_sources', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_activity_template_id');
                $table->unsignedBigInteger('source_id');
                $table->primary(['crop_activity_template_id', 'source_id'], 'pk_activity_template_sources');
                $table->foreign('crop_activity_template_id', 'fk_activity_template_sources_template')
                    ->references('id')->on('crop_activity_templates')->cascadeOnDelete();
                $table->foreign('source_id', 'fk_activity_template_sources_source')
                    ->references('id')->on('knowledge_sources')->restrictOnDelete();
                $table->index(['source_id', 'crop_activity_template_id'], 'idx_activity_template_sources_reverse');
            });
        }

        if (Schema::hasColumn('crop_activity_templates', 'crop_variety_id')
            && Schema::hasColumn('crop_activity_templates', 'season_id')
            && Schema::hasColumn('crop_activity_templates', 'source_id')) {
            DB::table('crop_activity_templates')->select(['id', 'crop_variety_id', 'season_id', 'source_id'])
                ->orderBy('id')->chunkById(500, function ($templates): void {
                    foreach ($templates as $template) {
                        DB::table('crop_activity_templates')->where('id', $template->id)->update([
                            'variety_scope' => $template->crop_variety_id === null ? 'all' : 'specific',
                            'season_scope' => 'specific',
                            'schedule_basis' => 'fixed_offsets',
                            'activity_type' => 'task',
                            'allow_status_update' => true,
                            'allow_expenses' => false,
                            'allow_labour' => false,
                            'allow_notes' => true,
                            'allow_attachments' => false,
                            'allow_rescheduling' => false,
                            'smart_actions' => null,
                            'trigger_type' => 'scheduled',
                            'trigger_config' => null,
                        ]);
                        if ($template->crop_variety_id !== null) {
                            DB::table('crop_activity_template_varieties')->insertOrIgnore([
                                'crop_activity_template_id' => $template->id,
                                'crop_variety_id' => $template->crop_variety_id,
                            ]);
                        }
                        if ($template->season_id !== null) {
                            DB::table('crop_activity_template_seasons')->insertOrIgnore([
                                'crop_activity_template_id' => $template->id,
                                'season_id' => $template->season_id,
                            ]);
                        }
                        if ($template->source_id !== null) {
                            DB::table('crop_activity_template_sources')->insertOrIgnore([
                                'crop_activity_template_id' => $template->id,
                                'source_id' => $template->source_id,
                            ]);
                        }
                    }
                });
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('crop_activity_templates', function (Blueprint $table): void {
                $table->unsignedBigInteger('crop_variety_id')->nullable()->change();
                $table->unsignedBigInteger('season_id')->nullable()->change();
                $table->unsignedBigInteger('source_id')->nullable()->change();
            });
        }

        $missingSeasons = DB::table('crop_activity_templates')
            ->leftJoin('crop_activity_template_seasons', 'crop_activity_template_seasons.crop_activity_template_id', '=', 'crop_activity_templates.id')
            ->whereNull('crop_activity_template_seasons.season_id')
            ->exists();
        if ($missingSeasons) {
            throw new RuntimeException('Migration aborted: not all existing season relationships could be verified.');
        }

        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->unsignedInteger('start_offset_days')->nullable()->change();
            $table->unsignedInteger('end_offset_days')->nullable()->change();
        });

        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $existingIndexes = collect(Schema::getIndexes('crop_activity_templates'))->pluck('name')->all();
            if (in_array('idx_activity_templates_crop_season_active', $existingIndexes, true)) {
                $table->dropIndex('idx_activity_templates_crop_season_active');
            }
            if (in_array('idx_activity_templates_stage_variety', $existingIndexes, true)) {
                $table->dropIndex('idx_activity_templates_stage_variety');
            }
            if (! in_array('idx_cat_crop_active', $existingIndexes, true)) {
                $table->index(['crop_id', 'is_active'], 'idx_cat_crop_active');
            }
            if (! in_array('idx_cat_stage', $existingIndexes, true)) {
                $table->index(['crop_stage_id'], 'idx_cat_stage');
            }
        });
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $legacyColumns = array_values(array_filter(
                ['crop_variety_id', 'season_id', 'source_id'],
                fn (string $column): bool => Schema::hasColumn('crop_activity_templates', $column),
            ));
            if (DB::getDriverName() !== 'sqlite') {
                foreach ($legacyColumns as $column) {
                    $table->dropForeign([$column]);
                }
            }
            if ($legacyColumns !== [] && DB::getDriverName() !== 'sqlite') {
                $table->dropColumn($legacyColumns);
            }
        });
    }

    /**
     * Rollback restores singular legacy values from the first pivot selection.
     * Multiple/all selections cannot be represented by the legacy schema.
     */
    public function down(): void
    {
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->unsignedBigInteger('crop_variety_id')->nullable();
            $table->unsignedBigInteger('season_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
        });
        DB::table('crop_activity_templates')->orderBy('id')->chunkById(500, function ($templates): void {
            foreach ($templates as $template) {
                DB::table('crop_activity_templates')->where('id', $template->id)->update([
                    'crop_variety_id' => DB::table('crop_activity_template_varieties')
                        ->where('crop_activity_template_id', $template->id)->min('crop_variety_id'),
                    'season_id' => DB::table('crop_activity_template_seasons')
                        ->where('crop_activity_template_id', $template->id)->min('season_id'),
                    'source_id' => DB::table('crop_activity_template_sources')
                        ->where('crop_activity_template_id', $template->id)->min('source_id'),
                ]);
            }
        });
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->foreign('crop_variety_id', 'crop_activity_templates_crop_variety_id_foreign')
                ->references('id')->on('crop_varieties')->restrictOnDelete();
            $table->foreign('season_id', 'crop_activity_templates_season_id_foreign')
                ->references('id')->on('seasons')->restrictOnDelete();
            $table->foreign('source_id', 'crop_activity_templates_source_id_foreign')
                ->references('id')->on('knowledge_sources')->restrictOnDelete();
            $table->dropIndex('idx_cat_crop_active');
            $table->dropIndex('idx_cat_stage');
            $table->index(['crop_id', 'season_id', 'is_active'], 'idx_activity_templates_crop_season_active');
            $table->index(['crop_stage_id', 'crop_variety_id'], 'idx_activity_templates_stage_variety');
            $table->dropColumn([
                'variety_scope', 'season_scope', 'schedule_basis', 'activity_type',
                'allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes',
                'allow_attachments', 'allow_rescheduling', 'smart_actions',
                'trigger_type', 'trigger_config',
            ]);
        });
        Schema::dropIfExists('crop_activity_template_sources');
        Schema::dropIfExists('crop_activity_template_seasons');
        Schema::dropIfExists('crop_activity_template_varieties');
        Schema::table('crop_activity_templates', function (Blueprint $table): void {
            $table->integer('start_offset_days')->nullable()->change();
            $table->integer('end_offset_days')->nullable()->change();
        });
    }
};

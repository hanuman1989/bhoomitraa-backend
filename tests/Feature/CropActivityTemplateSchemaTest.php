<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('crop activity template migrations create the required three tables and can be reversed and reapplied', function () {
    $original = DB::getDefaultConnection();
    config()->set('database.connections.activity_template_schema_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('activity_template_schema_test');
    $templates = require database_path('migrations/2026_11_05_190352_create_crop_activity_templates_table.php');
    $translations = require database_path('migrations/2026_11_05_190353_create_crop_activity_template_translations_table.php');
    $regions = require database_path('migrations/2026_11_05_190353_create_crop_activity_template_regions_table.php');

    try {
        foreach (['crops', 'crop_varieties', 'activity_categories', 'crop_stages', 'seasons', 'knowledge_sources', 'regions'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
            DB::table($table)->insert(['id' => 1]);
        }
        $templates->up();
        $translations->up();
        $regions->up();

        expect(Schema::getColumnListing('crop_activity_templates'))->toBe([
            'id', 'crop_id', 'crop_variety_id', 'category_id', 'crop_stage_id', 'season_id', 'schedule_type',
            'start_offset_days', 'end_offset_days', 'region_scope', 'priority', 'source_id', 'is_active', 'created_at', 'updated_at',
        ]);
        expect(Schema::getColumnListing('crop_activity_template_translations'))->toBe([
            'id', 'crop_activity_template_id', 'locale', 'title', 'description', 'instructions', 'created_at', 'updated_at',
        ]);
        expect(Schema::getColumnListing('crop_activity_template_regions'))->toBe(['crop_activity_template_id', 'region_id']);
        DB::table('crop_activity_templates')->insert([
            'crop_id' => 1, 'category_id' => 1, 'season_id' => 1, 'source_id' => 1,
            'schedule_type' => 'after_sowing', 'region_scope' => 'all',
            'created_at' => '2026-11-06 09:00:00.123456', 'updated_at' => '2026-11-06 09:00:00.123456',
        ]);
        $record = DB::table('crop_activity_templates')->first();
        expect($record->priority)->toBe('medium');
        expect((bool) $record->is_active)->toBeFalse();
        expect($record->crop_variety_id)->toBeNull();
        expect($record->crop_stage_id)->toBeNull();
        $regions->down();
        $translations->down();
        $templates->down();
        expect(Schema::hasTable('crop_activity_templates'))->toBeFalse();
        expect(Schema::hasTable('crop_activity_template_translations'))->toBeFalse();
        expect(Schema::hasTable('crop_activity_template_regions'))->toBeFalse();
        $templates->up();
        $translations->up();
        $regions->up();
        expect(DB::table('crop_activity_templates')->count())->toBe(0);
        expect(DB::table('crop_activity_template_translations')->count())->toBe(0);
        expect(DB::table('crop_activity_template_regions')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('activity_template_schema_test');
    }
});

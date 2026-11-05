<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('timeline migrations enforce foreign keys composite pivot keys and safe rollback order', function () {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.timeline_migration_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('timeline_migration_test');
    $timelines = require database_path('migrations/2026_11_05_112027_create_crop_stage_timelines_table.php');
    $regions = require database_path('migrations/2026_11_05_112028_create_crop_stage_timeline_regions_table.php');

    try {
        foreach (['crop_stages', 'crop_varieties', 'seasons', 'knowledge_sources', 'regions'] as $table) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
            });
            DB::table($table)->insert(['id' => 1]);
        }
        $timelines->up();
        $regions->up();
        expect(Schema::getColumnListing('crop_stage_timelines'))->toBe([
            'id', 'crop_stage_id', 'crop_variety_id', 'season_id', 'region_scope',
            'days_after_sowing_min', 'days_after_sowing_max', 'source_id', 'is_active', 'created_at', 'updated_at',
        ]);
        expect(Schema::getColumnListing('crop_stage_timeline_regions'))->toBe(['crop_stage_timeline_id', 'region_id']);
        $attributes = [
            'crop_stage_id' => 1, 'crop_variety_id' => 1, 'season_id' => 1, 'region_scope' => 'specific',
            'days_after_sowing_min' => 0, 'days_after_sowing_max' => 10, 'source_id' => 1,
        ];
        DB::table('crop_stage_timelines')->insert(['id' => 1, ...$attributes]);
        DB::table('crop_stage_timelines')->insert(['id' => 2, ...$attributes]);
        DB::table('crop_stage_timeline_regions')->insert(['crop_stage_timeline_id' => 1, 'region_id' => 1]);
        DB::table('crop_stage_timeline_regions')->insert(['crop_stage_timeline_id' => 2, 'region_id' => 1]);

        expect((bool) DB::table('crop_stage_timelines')->where('id', 1)->value('is_active'))->toBeFalse();
        expect(DB::table('crop_stage_timelines')->where('id', 1)->value('created_at'))->toBeNull();
        expect(DB::table('crop_stage_timelines')->where('id', 1)->value('updated_at'))->toBeNull();
        expect(fn () => DB::table('crop_stage_timeline_regions')->insert(['crop_stage_timeline_id' => 1, 'region_id' => 1]))
            ->toThrow(UniqueConstraintViolationException::class);
        foreach (['crop_stages', 'crop_varieties', 'seasons', 'knowledge_sources', 'regions'] as $table) {
            expect(fn () => DB::table($table)->where('id', 1)->delete())->toThrow(QueryException::class);
        }
        DB::table('crop_stage_timelines')->where('id', 1)->delete();
        expect(DB::table('crop_stage_timeline_regions')->pluck('crop_stage_timeline_id')->all())->toBe([2]);
        expect(DB::table('regions')->count())->toBe(1);

        $regions->down();
        $timelines->down();
        expect(Schema::hasTable('crop_stage_timelines'))->toBeFalse();
        expect(Schema::hasTable('crop_stage_timeline_regions'))->toBeFalse();
        expect(DB::table('crop_stages')->count())->toBe(1);
        $timelines->up();
        $regions->up();
        expect(DB::table('crop_stage_timelines')->count())->toBe(0);
        expect(DB::table('crop_stage_timeline_regions')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('timeline_migration_test');
    }
});

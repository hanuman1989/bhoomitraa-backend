<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('growth stage migrations enforce ownership defaults and rollback without removing the parent catalogue', function () {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.crop_stage_migration_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('crop_stage_migration_test');
    $stages = require database_path('migrations/2026_11_05_085056_create_crop_stages_table.php');
    $translations = require database_path('migrations/2026_11_05_085057_create_crop_stage_translations_table.php');

    try {
        Schema::create('crops', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('crops')->insert(['id' => 1]);
        $stages->up();
        $translations->up();

        expect(Schema::getColumnListing('crop_stages'))->toBe([
            'id', 'crop_id', 'code', 'sequence', 'is_active', 'created_at', 'updated_at',
        ]);
        expect(Schema::getColumnListing('crop_stage_translations'))->toBe([
            'id', 'crop_stage_id', 'locale', 'name', 'description', 'created_at', 'updated_at',
        ]);
        DB::table('crop_stages')->insert(['id' => 1, 'crop_id' => 1, 'code' => 'example', 'sequence' => 1]);
        DB::table('crop_stage_translations')->insert(['crop_stage_id' => 1, 'locale' => 'en', 'name' => 'Example']);
        expect((bool) DB::table('crop_stages')->value('is_active'))->toBeFalse();
        foreach (['crop_stages', 'crop_stage_translations'] as $table) {
            expect(DB::table($table)->value('created_at'))->toBeNull();
            expect(DB::table($table)->value('updated_at'))->toBeNull();
        }
        expect(fn () => DB::table('crops')->where('id', 1)->delete())->toThrow(QueryException::class);
        DB::table('crop_stages')->where('id', 1)->delete();
        expect(DB::table('crop_stage_translations')->count())->toBe(0);

        $translations->down();
        $stages->down();
        expect(Schema::hasTable('crop_stages'))->toBeFalse();
        expect(Schema::hasTable('crop_stage_translations'))->toBeFalse();
        expect(DB::table('crops')->count())->toBe(1);

        $stages->up();
        $translations->up();
        expect(DB::table('crop_stages')->count())->toBe(0);
        expect(DB::table('crop_stage_translations')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('crop_stage_migration_test');
    }
});

<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('season migrations create exactly the catalogue tables and can be rolled back and reapplied', function () {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.season_migration_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('season_migration_test');
    $seasons = require database_path('migrations/2026_11_05_051704_create_seasons_table.php');
    $translations = require database_path('migrations/2026_11_05_051705_create_season_translations_table.php');

    try {
        $seasons->up();
        $translations->up();

        expect(Schema::getColumnListing('seasons'))->toBe(['id', 'slug', 'is_active', 'created_at', 'updated_at']);
        expect(Schema::getColumnListing('season_translations'))->toBe([
            'id', 'season_id', 'locale', 'name', 'description', 'created_at', 'updated_at',
        ]);
        DB::table('seasons')->insert([
            'slug' => 'rabi', 'created_at' => '2026-11-05 05:00:00.123456', 'updated_at' => '2026-11-05 05:00:00.123456',
        ]);
        expect((bool) DB::table('seasons')->value('is_active'))->toBeFalse();

        $translations->down();
        $seasons->down();
        expect(Schema::hasTable('seasons'))->toBeFalse();
        expect(Schema::hasTable('season_translations'))->toBeFalse();

        $seasons->up();
        $translations->up();
        expect(DB::table('seasons')->count())->toBe(0);
        expect(DB::table('season_translations')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('season_migration_test');
    }
});

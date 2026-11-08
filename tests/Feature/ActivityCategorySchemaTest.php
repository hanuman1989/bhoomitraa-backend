<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('activity category migrations create only the two catalogue tables and support rollback and reapplication', function () {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.activity_category_migration_test', [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('activity_category_migration_test');
    $categories = require database_path('migrations/2026_11_05_175236_create_activity_categories_table.php');
    $translations = require database_path('migrations/2026_11_05_175237_create_activity_category_translations_table.php');

    try {
        $categories->up();
        $translations->up();

        expect(Schema::getColumnListing('activity_categories'))->toBe([
            'id', 'slug', 'icon', 'is_system', 'is_active', 'created_at', 'updated_at',
        ]);
        expect(Schema::getColumnListing('activity_category_translations'))->toBe([
            'id', 'activity_category_id', 'locale', 'name', 'description', 'created_at', 'updated_at',
        ]);
        DB::table('activity_categories')->insert([
            'slug' => 'irrigation', 'created_at' => '2026-11-05 10:00:00.123456', 'updated_at' => '2026-11-05 10:00:00.123456',
        ]);
        $category = DB::table('activity_categories')->first();
        expect((bool) $category->is_active)->toBeFalse();
        expect((bool) $category->is_system)->toBeFalse();
        expect($category->icon)->toBeNull();

        $translations->down();
        $categories->down();
        expect(Schema::hasTable('activity_categories'))->toBeFalse();
        expect(Schema::hasTable('activity_category_translations'))->toBeFalse();
        $categories->up();
        $translations->up();
        expect(DB::table('activity_categories')->count())->toBe(0);
        expect(DB::table('activity_category_translations')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('activity_category_migration_test');
    }
});

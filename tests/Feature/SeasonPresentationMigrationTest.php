<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @param Closure(): void $test */
function withSeasonPresentationDatabase(Closure $test): void
{
    $original = DB::getDefaultConnection();
    config()->set('database.connections.season_presentation_test', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
    DB::setDefaultConnection('season_presentation_test');
    try {
        $test();
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('season_presentation_test');
    }
}

test('adds nullable columns and rolls back only those columns while preserving legacy rows translations and references', function () {
    withSeasonPresentationDatabase(function (): void {
        $legacy = require database_path('migrations/2026_11_05_051704_create_seasons_table.php');
        $translations = require database_path('migrations/2026_11_05_051705_create_season_translations_table.php');
        $presentation = require database_path('migrations/2026_11_16_144329_add_start_month_end_month_and_icon_to_seasons_table.php');
        $legacy->up();
        $translations->up();
        $id = DB::table('seasons')->insertGetId(['slug' => 'custom', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        DB::table('season_translations')->insert(['season_id' => $id, 'locale' => 'en', 'name' => 'Custom season', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        Schema::create('season_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->restrictOnDelete();
        });
        DB::table('season_references')->insert(['season_id' => $id]);
        $before = DB::table('seasons')->where('id', $id)->first();

        $presentation->up();
        $row = DB::table('seasons')->where('id', $id)->first();
        expect($row->start_month)->toBeNull();
        expect($row->end_month)->toBeNull();
        expect($row->icon)->toBeNull();
        expect(Schema::getColumnListing('seasons'))->toBe(['id', 'slug', 'is_active', 'created_at', 'updated_at', 'start_month', 'end_month', 'icon']);
        DB::table('seasons')->where('id', $id)->update(['start_month' => 10, 'end_month' => 3, 'icon' => 'sprout']);
        $presentation->down();

        expect(DB::table('seasons')->where('id', $id)->first())->toEqual($before);
        expect(DB::table('season_translations')->value('name'))->toBe('Custom season');
        expect(DB::table('season_references')->value('season_id'))->toBe($id);
        expect(Schema::getColumnListing('seasons'))->toBe(['id', 'slug', 'is_active', 'created_at', 'updated_at']);
        $presentation->up();
        expect(DB::table('seasons')->where('id', $id)->value('icon'))->toBeNull();
    });
});

test('backfills only missing standard-slug metadata and preserves configured pairs icons custom seasons and timestamps', function () {
    withSeasonPresentationDatabase(function (): void {
        (require database_path('migrations/2026_11_05_051704_create_seasons_table.php'))->up();
        (require database_path('migrations/2026_11_16_144329_add_start_month_end_month_and_icon_to_seasons_table.php'))->up();
        foreach ([
            ['slug' => 'rabi', 'start_month' => 12, 'end_month' => 4, 'icon' => 'leaf'],
            ['slug' => 'kharif', 'start_month' => null, 'end_month' => null, 'icon' => 'sprout'],
            ['slug' => 'zaid', 'start_month' => 8, 'end_month' => 1, 'icon' => null],
            ['slug' => 'custom', 'start_month' => null, 'end_month' => null, 'icon' => null],
        ] as $row) {
            DB::table('seasons')->insert([...$row, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-02']);
        }
        $backfill = require database_path('migrations/2026_11_16_144330_backfill_standard_season_presentation_fields.php');

        $backfill->up();

        $rows = DB::table('seasons')->orderBy('id')->get()->keyBy('slug');
        expect([$rows['rabi']->start_month, $rows['rabi']->end_month, $rows['rabi']->icon])->toBe([12, 4, 'leaf']);
        expect([$rows['kharif']->start_month, $rows['kharif']->end_month, $rows['kharif']->icon])->toBe([6, 10, 'sprout']);
        expect([$rows['zaid']->start_month, $rows['zaid']->end_month, $rows['zaid']->icon])->toBe([8, 1, 'leaf']);
        expect([$rows['custom']->start_month, $rows['custom']->end_month, $rows['custom']->icon])->toBe([null, null, null]);
        expect($rows->pluck('updated_at')->unique()->all())->toBe(['2026-01-02']);
        $backfill->down();
        expect(DB::table('seasons')->orderBy('id')->get()->toArray())->toEqual($rows->values()->all());
    });
});

test('normal migration reruns do not restore presentation values intentionally cleared after the one-time backfill', function () {
    withSeasonPresentationDatabase(function (): void {
        $paths = array_map(fn (string $name): string => database_path('migrations/'.$name), [
            '2026_11_05_051704_create_seasons_table.php', '2026_11_05_051705_create_season_translations_table.php',
        ]);
        expect(Artisan::call('migrate', ['--database' => 'season_presentation_test', '--path' => $paths, '--realpath' => true, '--no-interaction' => true]))->toBe(0);
        DB::table('seasons')->insert(['slug' => 'rabi', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']);
        $paths = array_map(fn (string $name): string => database_path('migrations/'.$name), [
            '2026_11_16_144329_add_start_month_end_month_and_icon_to_seasons_table.php', '2026_11_16_144330_backfill_standard_season_presentation_fields.php',
        ]);
        $arguments = ['--database' => 'season_presentation_test', '--path' => $paths, '--realpath' => true, '--no-interaction' => true];
        expect(Artisan::call('migrate', $arguments))->toBe(0);
        expect(DB::table('seasons')->value('start_month'))->toBe(10);
        expect(DB::table('seasons')->value('icon'))->toBe('sprout');
        DB::table('seasons')->update(['start_month' => null, 'end_month' => null, 'icon' => null]);

        expect(Artisan::call('migrate', $arguments))->toBe(0);

        expect(DB::table('seasons')->value('start_month'))->toBeNull();
        expect(DB::table('seasons')->value('end_month'))->toBeNull();
        expect(DB::table('seasons')->value('icon'))->toBeNull();
        expect(DB::table('seasons')->count())->toBe(1);
    });
});

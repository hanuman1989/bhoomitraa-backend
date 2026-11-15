<?php

use App\Models\Farm;
use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function withLegacyAreaDatabase(Closure $assertions): void
{
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.legacy_area_test', [
        ...config('database.connections.sqlite'), 'database' => ':memory:', 'url' => null,
    ]);
    DB::setDefaultConnection('legacy_area_test');
    try {
        $migrationPath = database_path('migrations/2026_11_15_135002_centralize_farm_area_measurements.php');
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => $path !== $migrationPath));
        expect(Artisan::call('migrate', ['--path' => $paths, '--realpath' => true, '--force' => true, '--no-interaction' => true]))->toBe(0);
        $assertions(require $migrationPath);
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::purge('legacy_area_test');
    }
}

function legacyFarmActualMeasurement(Farm $farm, string $area, string $unit, string $squareMetres): void
{
    DB::table('farms')->where('id', $farm->id)->update([
        'boundary' => json_encode(['type' => 'MultiPolygon', 'coordinates' => [[[
            [0, 0], [0.001, 0], [0.001, 0.001], [0, 0.001], [0, 0],
        ]]]], JSON_THROW_ON_ERROR),
        'boundary_source' => 'manual', 'actual_area' => $area, 'actual_area_unit' => $unit, 'actual_area_sqm' => $squareMetres,
    ]);
}

test('the area migration preserves independent geometry precision and unresolved recorded units', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $farmer = User::factory()->create();
        $profile = FarmerProfile::factory()->for($farmer)->create(['preferred_units' => 'bigha']);
        $farm = Farm::factory()->for($farmer)->create(['area' => '4.000', 'area_unit' => 'bigha', 'khasra_number' => '123']);
        legacyFarmActualMeasurement($farm, '0.395', 'ha', '3946.970097');
        $before = $farm->fresh()->getRawOriginal();

        $migration->up();

        $converted = $farm->fresh();
        expect($converted->getRawOriginal())->toMatchArray(array_diff_key($before, array_flip(['actual_area', 'actual_area_unit', 'actual_area_sqm'])));
        expect($converted->actual_area)->toBe('3946.97009700');
        expect($converted->actual_area_unit)->toBe('sqm');
        expect($converted->actual_area_sqm)->toBe('3946.970097');
        expect($converted->area_sqm)->toBeNull();
        expect($profile->fresh()->preferred_units)->toBe('bigha');
        expect(Schema::getColumnListing('farms'))->not->toContain('area_sqm', 'actual_area_sqm', 'area_unit_conversion_id');
        $newFarmer = User::factory()->create();
        expect($newFarmer->farmerProfile()->create([])->fresh()->preferred_units)->toBe('hectare');
    });
});

test('verified legacy actual units are retained when they reconstruct the stored square metres exactly', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $farm = Farm::factory()->create(['area' => '2.000', 'area_unit' => 'ha']);
        legacyFarmActualMeasurement($farm, '1.000', 'ha', '10000.000000');

        $migration->up();

        expect($farm->fresh()->actual_area)->toBe('1.00000000');
        expect($farm->fresh()->actual_area_unit)->toBe('ha');
        expect($farm->fresh()->actual_area_sqm)->toBe('10000');
        expect($farm->fresh()->area_unit)->toBe('ha');
    });
});

test('ambiguous legacy actual units are recovered from the independent square metre measurement', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $farm = Farm::factory()->create(['area' => '2.000', 'area_unit' => 'bigha']);
        legacyFarmActualMeasurement($farm, '1.000', 'bigha', '10000.123456');

        $migration->up();

        expect($farm->fresh()->actual_area_unit)->toBe('sqm');
        expect($farm->fresh()->actual_area_sqm)->toBe('10000.123456');
        expect($farm->fresh()->area_unit)->toBe('bigha');
    });
});

test('a conflicting recorded square metre column stops migration before modifying the schema or data', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        Schema::table('farms', fn (Blueprint $table) => $table->decimal('area_sqm', 18, 6)->nullable());
        $farm = Farm::factory()->create(['area' => '2.000', 'area_unit' => 'ha']);
        DB::table('farms')->where('id', $farm->id)->update(['area_sqm' => '1.000000']);
        $before = DB::table('farms')->where('id', $farm->id)->first();

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Resolve the stored recorded square-metre measurement');

        expect(DB::table('farms')->where('id', $farm->id)->first())->toEqual($before);
        expect(Schema::hasColumn('farms', 'area_sqm'))->toBeTrue();
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeTrue();
    });
});

test('unrepresentable historical actual measurements stop migration before deleting data', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $farm = Farm::factory()->create();
        legacyFarmActualMeasurement($farm, '99999999.999', 'bigha', '999999999999.123456');
        $before = $farm->fresh()->getRawOriginal();

        expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'cannot be preserved at the target precision');

        expect($farm->fresh()->getRawOriginal())->toBe($before);
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeTrue();
    });
});

test('rollback refuses to truncate populated farm measurements', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $migration->up();
        $farm = Farm::factory()->create();
        $before = $farm->fresh()->getRawOriginal();

        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back populated farm measurements');

        expect($farm->fresh()->getRawOriginal())->toBe($before);
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeFalse();
    });
});

test('empty farm schemas can roll back and migrate again without losing boundary constraints', function () {
    withLegacyAreaDatabase(function (Migration $migration): void {
        $migration->up();

        $migration->down();

        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeTrue();
        $farm = Farm::factory()->create();
        expect(fn () => DB::table('farms')->where('id', $farm->id)->update(['actual_area_sqm' => '1.000000']))
            ->toThrow(QueryException::class);
        $migration->up();
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeFalse();
        expect($farm->fresh()->area_sqm)->toBe('10000');
    });
});

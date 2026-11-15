<?php

use App\Models\AdminUser;
use App\Models\Farm;
use App\Models\FarmerProfile;
use App\Models\Region;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function areaFarmPayload(string $unit): array
{
    $country = Region::factory()->create();
    $state = Region::factory()->under($country)->create();
    $district = Region::factory()->under($state)->create();

    return ['name' => 'Measured farm', 'area' => '4.000', 'area_unit' => $unit,
        'state_id' => $state->id, 'district_id' => $district->id, 'village' => 'Test village'];
}

/** @return array<string, mixed> */
function areaFarmBoundary(): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[
        [0, 0], [0.001, 0], [0.001, 0.001], [0, 0.001], [0, 0],
    ]]]];
}

test('new farms accept the configured units and ignore client calculated measurements', function (string $unit) {
    $farmer = User::factory()->create();
    $payload = [...areaFarmPayload($unit), 'area_sqm' => 1, 'actual_area' => 1, 'actual_area_unit' => 'acre', 'actual_area_sqm' => 1];

    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('data.area', '4.000')->assertJsonPath('data.area_unit', $unit)
        ->assertJsonPath('data.actual_area', null)->assertJsonPath('data.actual_area_unit', null)->assertJsonPath('data.actual_area_sqm', null);

    expect($response->json('data.area_sqm'))->toBeString()->not->toBe('1');
    $this->assertDatabaseHas('farms', ['id' => $response->json('data.id'), 'area' => 4, 'area_unit' => $unit, 'actual_area' => null]);
    expect(Schema::hasColumn('farms', 'area_sqm'))->toBeFalse();
    expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeFalse();
})->with(['bigha_kaccha', 'bigha_pakka', 'hectare', 'acre', 'sqm', 'sqft', 'sqyd', 'guntha']);

test('new farm writes reject legacy ambiguous and unsupported unit codes with 422', function (string $unit) {
    $farmer = User::factory()->create();
    $payload = areaFarmPayload($unit);

    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('errors.area_unit.0', 'The selected area unit is invalid.');

    $this->assertDatabaseCount('farms', 0);
})->with(['bigha', 'biswa', 'ha', 'metric', 'imperial']);

test('calculated farm measurements change with stored attributes without issuing queries', function () {
    $farm = Farm::factory()->make(['area' => '4.000', 'area_unit' => 'bigha_pakka',
        'actual_area' => '3.00000000', 'actual_area_unit' => 'bigha_pakka']);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($farm->area_sqm)->toBe('10117.141056');
    expect($farm->actual_area_sqm)->toBe('7587.855792');
    $farm->area = '5.000';
    expect($farm->area_sqm)->toBe('12646.42632');
    $farm->area_unit = 'bigha';
    expect($farm->area_sqm)->toBeNull();
    $farm->actual_area = null;
    expect($farm->actual_area_sqm)->toBeNull();
    expect(DB::getQueryLog())->toBe([]);
    expect($farm->getAttributes())->not->toHaveKeys(['area_sqm', 'actual_area_sqm']);
    DB::disableQueryLog();
});

test('mixed Bigha and hectare totals use the full owned dataset and the profile preference', function () {
    $farmer = User::factory()->create();
    FarmerProfile::factory()->for($farmer)->create(['preferred_units' => 'bigha_pakka']);
    Farm::factory()->for($farmer)->create(['area' => '4.000', 'area_unit' => 'bigha_pakka']);
    Farm::factory()->for($farmer)->inactive()->create(['area' => '2.000', 'area_unit' => 'hectare']);
    Farm::factory()->create(['area' => '999.000']);

    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->getJson('/api/account/farms/summary?page=2&per_page=1&user_id=999&status=active')->assertOk()
        ->assertJsonPath('data.total_farms', 2)->assertJsonPath('data.active_farms', 1)->assertJsonPath('data.inactive_farms', 1)
        ->assertJsonPath('data.total_land_area.value', '11.907')->assertJsonPath('data.total_land_area.unit', 'bigha_pakka')
        ->assertJsonPath('data.total_land_area.area_sqm', '30117.141056')->assertJsonPath('data.total_land_area.basis', 'government_record')
        ->assertJsonPath('data.total_land_area.conversion_available', true)->assertJsonPath('data.total_land_area.unresolved_farms_count', 0)
        ->assertJsonPath('data.total_land_area.preference_resolution_required', false)->assertJsonPath('pagination', null);
});

test('legacy preferences use an explicit hectare fallback without relabeling saved farms', function (string $preference, string $unit, bool $requiresResolution) {
    $farmer = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create(['preferred_units' => $preference]);
    Farm::factory()->for($farmer)->create(['area' => '2.000', 'area_unit' => 'ha']);

    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->getJson('/api/account/farms/summary')->assertOk()
        ->assertJsonPath('data.total_land_area.value', '2.000')->assertJsonPath('data.total_land_area.unit', $unit)
        ->assertJsonPath('data.total_land_area.preference_resolution_required', $requiresResolution);

    expect($profile->fresh()->preferred_units)->toBe($preference);
    $this->assertDatabaseHas('farms', ['user_id' => $farmer->id, 'area_unit' => 'ha', 'area' => 2]);
})->with([['ha', 'hectare', false], ['bigha', 'hectare', true], ['metric', 'hectare', true], ['imperial', 'hectare', true]]);

test('unit only edits convert the recorded measurement and synchronize actual area from the unchanged boundary', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['area' => '2.000', 'area_unit' => 'hectare']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => areaFarmBoundary(), 'boundary_source' => 'gps', 'gps_accuracy_m' => '4.80'])
        ->assertOk();
    $before = $farm->fresh()->getRawOriginal();

    $response = $this->patchJson('/api/account/farms/'.$farm->id, ['area_unit' => 'acre'])
        ->assertOk()->assertJsonPath('data.area', '4.942')->assertJsonPath('data.area_unit', 'acre')
        ->assertJsonPath('data.actual_area_unit', 'acre')->assertJsonPath('data.actual_area', '3.05529640');

    expect((float) $response->json('data.area_sqm'))->toEqualWithDelta(20000, 2.024);
    expect((float) $response->json('data.actual_area_sqm'))->toEqualWithDelta(12364.345868, 0.00003);
    expect($farm->fresh()->getRawOriginal('boundary'))->toBe($before['boundary']);
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'area' => '4.942', 'actual_area_unit' => 'acre', 'gps_accuracy_m' => '4.80']);
});

test('unrelated farm edits preserve all measurements and explicit corrections replace the recorded measurement', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['area' => '4.000', 'area_unit' => 'bigha_pakka']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => areaFarmBoundary(), 'boundary_source' => 'manual'])->assertOk();
    $before = $farm->fresh()->getRawOriginal();

    $this->patchJson('/api/account/farms/'.$farm->id, ['name' => 'Renamed'])->assertOk();

    expect($farm->fresh()->getRawOriginal())->toMatchArray(array_diff_key($before, array_flip(['name', 'updated_at'])));
    $this->patchJson('/api/account/farms/'.$farm->id, ['area' => '2.000', 'area_unit' => 'hectare'])
        ->assertOk()->assertJsonPath('data.area_sqm', '20000')->assertJsonPath('data.actual_area_unit', 'hectare')
        ->assertJsonPath('data.actual_area', '1.23643459');
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'area' => 2, 'area_unit' => 'hectare']);
});

test('unconvertible or overflowing unit only edits return 422 and preserve the complete farm', function (string $area, string $from, string $to) {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['area' => $area, 'area_unit' => $from]);
    $before = $farm->fresh()->getRawOriginal();

    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id, ['area_unit' => $to])->assertUnprocessable()->assertJsonValidationErrors('area');

    expect($farm->fresh()->getRawOriginal())->toBe($before);
})->with([['4.000', 'bigha', 'bigha_pakka'], ['999999999.999', 'hectare', 'sqm'], ['0.001', 'sqm', 'hectare']]);

test('correcting an ambiguous recorded Bigha preserves the boundary derived physical measurement', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['area' => '4.000', 'area_unit' => 'bigha']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => areaFarmBoundary(), 'boundary_source' => 'manual'])
        ->assertOk()->assertJsonPath('data.area_sqm', null)->assertJsonPath('data.actual_area_unit', 'hectare');

    $response = $this->patchJson('/api/account/farms/'.$farm->id, ['area' => '4.000', 'area_unit' => 'bigha_pakka'])
        ->assertOk()->assertJsonPath('data.area_sqm', '10117.141056')->assertJsonPath('data.actual_area_unit', 'bigha_pakka');

    expect((float) $response->json('data.actual_area_sqm'))->toEqualWithDelta(12364.345868, 0.00003);
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'area' => 4, 'area_unit' => 'bigha_pakka']);
});

test('a profile preference change affects summary presentation and preserves saved farm measurements', function () {
    $farmer = User::factory()->create();
    FarmerProfile::factory()->for($farmer)->create(['preferred_units' => 'hectare']);
    $farm = Farm::factory()->for($farmer)->create(['area' => '2.000', 'area_unit' => 'acre']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => areaFarmBoundary(), 'boundary_source' => 'manual'])->assertOk();
    $before = $farm->fresh()->getRawOriginal();
    $this->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_land_area.value', '0.809');

    $this->patchJson('/api/account/profile/personal', ['first_name' => 'Farmer', 'last_name' => 'Kumar',
        'preferred_language' => 'hi', 'preferred_units' => 'acre'])
        ->assertOk()->assertJsonPath('data.farmer_profile.preferred_units', 'acre');

    expect($farm->fresh()->getRawOriginal())->toBe($before);
    $this->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_land_area.value', '2.000')
        ->assertJsonPath('data.total_land_area.unit', 'acre');
});

test('authenticated unit metadata reflects configuration and keeps Hindi labels', function () {
    $farmer = User::factory()->create();
    config()->set('area_units.units.hectare.label', 'Configured Hectare');

    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->getJson('/api/account/area-units')->assertOk()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.default_unit', 'hectare')->assertJsonCount(8, 'data.units')
        ->assertJsonPath('data.units.0.label_hi', 'कच्चा बीघा')->assertJsonPath('data.units.0.definition', '17424 square feet')
        ->assertJsonPath('data.units.2.label', 'Configured Hectare');

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_column($response->json('data.units'), 'value'))->not->toContain('bigha', 'ha');
    $response->assertJsonMissingPath('data.legacy_aliases');
});

test('unit metadata rejects missing credentials other identities and invalid abilities', function (string $identity, int $status) {
    if ($identity === 'admin') {
        $user = AdminUser::factory()->create();
        $this->withToken($user->createToken('admin', ['admin', 'frontend'])->plainTextToken);
    } elseif ($identity !== 'guest') {
        $user = User::factory()->create(['status' => $identity !== 'inactive']);
        $this->withToken($user->createToken('farm', $identity === 'wrong ability' ? ['admin'] : ['frontend'])->plainTextToken);
    }

    $this->getJson('/api/account/area-units')->assertStatus($status)->assertJsonPath('status', false)->assertJsonPath('data', null);
})->with([['guest', 401], ['admin', 401], ['wrong ability', 403], ['inactive', 403]]);

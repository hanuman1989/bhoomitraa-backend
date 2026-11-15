<?php

use App\Models\AdminUser;
use App\Models\Farm;
use App\Models\FarmerProfile;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

/** @return array{Region, Region, Region} */
function farmWizardRegions(): array
{
    $country = Region::factory()->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    $district = Region::factory()->under($state)->withEnglishName('Jaipur')->create();
    $tehsil = Region::factory()->under($district)->withEnglishName('Chaksu')->create();

    return [$state, $district, $tehsil];
}

/** @return array<string, mixed> */
function farmWizardPayload(Region $state, Region $district, array $overrides = []): array
{
    return array_replace(['name' => 'Main Farm', 'ownership_type' => 'owned', 'description' => 'Near the village',
        'area' => '10.000', 'area_unit' => 'hectare', 'state_id' => $state->id, 'district_id' => $district->id,
        'village' => 'Rampura', 'address' => 'Near Canal Road'], $overrides);
}

/** @return array<string, mixed> */
function farmWizardBoundary(): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[
        [0.0, 0.0], [0.001, 0.0], [0.001, 0.001], [0.0, 0.001], [0.0, 0.0],
    ]]]];
}

test('the final wizard submission creates one owned farm without modifying the residential profile', function () {
    [$state, $district, $tehsil] = farmWizardRegions();
    $state->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    $farmer = User::factory()->create();
    $other = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create(['village' => 'Residential village', 'preferred_units' => 'acre']);
    $profileBefore = $profile->fresh()->getRawOriginal();
    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, [
            'name' => '  मुख्य   खेत ', 'village' => ' रामपुरा ', 'tehsil_id' => $tehsil->id,
            'user_id' => $other->id, 'region_id' => $state->id, 'is_active' => false, 'area_source' => 'client_source',
            'actual_area' => 999, 'actual_area_sqm' => 999, 'actual_area_unit' => 'acre', 'khasra_number' => 'ignored',
            'creation_key' => 'forged', 'created_at' => '2000-01-01', 'boundary' => null, 'boundary_source' => null,
        ]), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.name', 'मुख्य खेत')->assertJsonPath('data.village', 'रामपुरा')
        ->assertJsonPath('data.area', '10.000')->assertJsonPath('data.area_unit', 'hectare')
        ->assertJsonPath('data.area_source', 'government_record')->assertJsonPath('data.region_id', $tehsil->id)
        ->assertJsonPath('data.state.name', 'राजस्थान')->assertJsonPath('data.district.name', 'Jaipur')
        ->assertJsonPath('data.tehsil.name', 'Chaksu')->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.has_boundary', false)->assertJsonPath('data.boundary', null)
        ->assertJsonPath('data.actual_area', null)->assertJsonPath('data.actual_area_unit', null)
        ->assertJsonPath('data.actual_area_sqm', null)->assertJsonPath('data.gps_accuracy_m', null)
        ->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null)->assertJsonPath('data.active_crop', null)
        ->assertJsonPath('data.khasra_number', null)->assertJsonMissingPath('data.user_id')
        ->assertJsonMissingPath('data.creation_key')->assertJsonMissingPath('data.creation_payload_hash');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $farm = $farmer->farms()->sole();
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'user_id' => $farmer->id, 'region_id' => $tehsil->id, 'name' => 'मुख्य खेत']);
    expect($farm->user->is($farmer))->toBeTrue();
    expect($farm->state->is($state))->toBeTrue();
    expect($farm->district->is($district))->toBeTrue();
    expect($farm->tehsil->is($tehsil))->toBeTrue();
    expect($farm->region->is($tehsil))->toBeTrue();
    expect($profile->fresh()->getRawOriginal())->toBe($profileBefore);
    $this->assertDatabaseCount('farms', 1);
    $this->deleteJson('/api/account/farms/'.$farm->id)->assertStatus(405);
    $this->assertDatabaseCount('farms', 1);
});

test('manual and GPS boundaries calculate area independently from government records', function (string $source) {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $payload = farmWizardPayload($state, $district, ['boundary' => farmWizardBoundary(), 'boundary_source' => $source,
        'gps_accuracy_m' => $source === 'gps' ? '4.80' : null, 'actual_area_sqm' => 1, 'actual_area' => 1000]);
    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
        ->assertJsonPath('data.has_boundary', true)->assertJsonPath('data.boundary.type', 'MultiPolygon')
        ->assertJsonPath('data.area', '10.000')->assertJsonPath('data.area_unit', 'hectare')
        ->assertJsonPath('data.actual_area_unit', 'hectare')->assertJsonPath('data.actual_area', '1.23643459')
        ->assertJsonPath('data.boundary_source', $source)->assertJsonPath('data.gps_accuracy_m', $source === 'gps' ? '4.80' : null)
        ->assertJsonPath('data.latitude', '0.0005000')->assertJsonPath('data.longitude', '0.0005000');
    expect((float) $response->json('data.actual_area_sqm'))->toBeGreaterThan(12364.3458)->toBeLessThan(12364.3460);
    $this->assertDatabaseHas('farms', ['id' => $response->json('data.id'), 'area' => 10, 'boundary_source' => $source, 'actual_area_unit' => 'hectare']);
})->with(['manual', 'gps']);

test('explicit Bigha factors remain fixed regardless of farm location', function () {
    [$state, $district, $tehsil] = farmWizardRegions();
    config()->set('farms.bigha_sqm_by_region', [$state->id => '2500', $district->id => '2000', $tehsil->id => '1000']);
    $farmer = User::factory()->create();
    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['tehsil_id' => $tehsil->id, 'area_unit' => 'bigha_pakka',
            'boundary' => farmWizardBoundary(), 'boundary_source' => 'manual']), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('data.actual_area_unit', 'bigha_pakka')->assertJsonPath('data.area_sqm', '25292.85264');
    expect($response->json('data.actual_area'))->toBe('4.88847424');
    $this->assertDatabaseHas('farms', ['id' => $response->json('data.id'), 'area' => 10, 'actual_area_unit' => 'bigha_pakka']);
});

test('small boundaries retain the selected unit using eight decimal actual area precision', function () {
    [$state, $district] = farmWizardRegions();
    $boundary = ['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [0.000001, 0], [0.000001, 0.000001], [0, 0.000001], [0, 0]]]]];
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => $boundary, 'boundary_source' => 'manual']),
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.actual_area_unit', 'hectare')
        ->assertJsonPath('data.actual_area', '0.00000124');
});

test('farm creation requires complete final fields and an idempotency header', function () {
    $farmer = User::factory()->create();
    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->post('/api/account/farms', [])->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'area', 'area_unit', 'state_id', 'district_id', 'village', 'idempotency_key',
        ])->assertJsonPath('errors.idempotency_key.0', 'Provide an Idempotency-Key header containing a UUID.')
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('farms', 0);
});

test('invalid create fields return 422 without persisting a partial farm', function (string $field, mixed $value) {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, [$field => $value]),
            ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('farms', 0);
})->with([
    'blank name' => ['name', '   '], 'Unicode blank name' => ['name', "\u{2003}"], 'name type' => ['name', 123],
    'long name' => ['name', str_repeat('a', 151)], 'ownership' => ['ownership_type', 'invented'],
    'description' => ['description', str_repeat('a', 5001)], 'zero area' => ['area', 0], 'negative area' => ['area', -1],
    'area precision' => ['area', '1.0001'], 'area overflow' => ['area', '1000000000.000'], 'units' => ['area_unit', 'kg'],
    'state integer' => ['state_id', 1.5], 'state exists' => ['state_id', 99999], 'district exists' => ['district_id', 99999],
    'tehsil exists' => ['tehsil_id', 99999], 'blank village' => ['village', '   '], 'long village' => ['village', str_repeat('a', 151)],
    'long address' => ['address', str_repeat('a', 501)], 'latitude' => ['latitude', 91], 'longitude' => ['longitude', 181],
    'coordinate pair' => ['latitude', 26.7], 'source without boundary' => ['boundary_source', 'manual'],
    'GPS without boundary' => ['gps_accuracy_m', 2], 'negative GPS' => ['gps_accuracy_m', -1],
    'GPS overflow' => ['gps_accuracy_m', 1000000], 'GPS precision' => ['gps_accuracy_m', '4.801'],
]);

test('new farms reject wrong region types mismatched hierarchy and inactive selections', function (string $kind, string $field) {
    [$state, $district, $tehsil] = farmWizardRegions();
    $farmer = User::factory()->create();
    $payload = farmWizardPayload($state, $district, ['tehsil_id' => $tehsil->id]);
    if ($kind === 'wrong type') {
        $payload[$field] = $field === 'state_id' ? $district->id : $state->id;
    } elseif ($kind === 'inactive') {
        (match ($field) {
            'state_id' => $state, 'district_id' => $district, default => $tehsil,
        })->update(['is_active' => false]);
    } elseif ($field === 'district_id') {
        $otherState = Region::factory()->under($state->parent)->create();
        $payload['district_id'] = Region::factory()->under($otherState)->create()->id;
        $payload['tehsil_id'] = null;
    } else {
        $otherDistrict = Region::factory()->under($state)->create();
        $payload['tehsil_id'] = Region::factory()->under($otherDistrict)->create()->id;
    }
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('farms', 0);
})->with([
    'district as state' => ['wrong type', 'state_id'], 'state as district' => ['wrong type', 'district_id'],
    'state as tehsil' => ['wrong type', 'tehsil_id'], 'district mismatch' => ['mismatch', 'district_id'],
    'tehsil mismatch' => ['mismatch', 'tehsil_id'], 'inactive state' => ['inactive', 'state_id'],
    'inactive district' => ['inactive', 'district_id'], 'inactive tehsil' => ['inactive', 'tehsil_id'],
]);

test('invalid boundary geometry returns a field error and no farm record', function (array $boundary) {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => $boundary, 'boundary_source' => 'manual']),
            ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('boundary');
    $this->assertDatabaseCount('farms', 0);
})->with([
    'Polygon instead of MultiPolygon' => [['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [0, 1], [0, 0]]]]],
    'missing coordinates' => [['type' => 'MultiPolygon']],
    'empty polygon' => [['type' => 'MultiPolygon', 'coordinates' => []]],
    'two parcels' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [0, 1], [0, 0]]], [[[2, 2], [3, 2], [2, 3], [2, 2]]]]]],
    'hole' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [0, 1], [0, 0]], [[0.1, 0.1], [0.2, 0.1], [0.1, 0.2], [0.1, 0.1]]]]]],
    'open ring' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [1, 1], [0, 1]]]]]],
    'insufficient vertices' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [0, 0]]]]]],
    'repeated vertices' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [1, 0], [0, 0]]]]]],
    'self crossing' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 1], [0, 1], [1, 0], [0, 0]]]]]],
    'self touch' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [2, 0], [1, 0], [1, 1], [0, 1], [0, 0]]]]]],
    'collinear' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 1], [2, 2], [0, 0]]]]]],
    'string coordinates' => [['type' => 'MultiPolygon', 'coordinates' => [[[['0', 0], [1, 0], [0, 1], ['0', 0]]]]]],
    'third dimension' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0, 1], [1, 0], [0, 1], [0, 0, 1]]]]]],
    'detectably reversed coordinates' => [['type' => 'MultiPolygon', 'coordinates' => [[[[26, 120], [27, 120], [27, 121], [26, 120]]]]]],
    'longitude out of range' => [['type' => 'MultiPolygon', 'coordinates' => [[[[181, 0], [182, 0], [182, 1], [181, 0]]]]]],
    'CRS injection' => [['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [0, 1], [0, 0]]]], 'crs' => ['name' => 'EPSG:3857']]],
]);

test('boundary metadata and configured payload limits are enforced', function (string $kind, string $field) {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $payload = farmWizardPayload($state, $district, ['boundary' => farmWizardBoundary(), 'boundary_source' => 'manual']);
    if ($kind === 'missing source') {
        unset($payload['boundary_source']);
    } elseif ($kind === 'manual accuracy') {
        $payload['gps_accuracy_m'] = 5;
    } elseif ($kind === 'points') {
        config()->set('farms.max_boundary_points', 4);
    } else {
        config()->set('farms.max_boundary_bytes', 10);
    }
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('farms', 0);
})->with([
    'missing source' => ['missing source', 'boundary_source'], 'manual accuracy' => ['manual accuracy', 'gps_accuracy_m'],
    'point limit' => ['points', 'boundary'], 'byte limit' => ['bytes', 'boundary'],
]);

test('clockwise rings normalize without changing their calculated area', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $boundary = farmWizardBoundary();
    $boundary['coordinates'][0][0] = array_reverse($boundary['coordinates'][0][0]);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => $boundary, 'boundary_source' => 'manual']),
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
        ->assertJsonPath('data.actual_area', '1.23643459')->assertJsonPath('data.boundary.coordinates.0.0.1', [0.001, 0]);
});

test('creation retries reuse a farm while distinct keys permit identical names and conflicting payloads return 409', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $payload = farmWizardPayload($state, $district);
    $key = (string) Str::uuid();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken);
    $id = $this->postJson('/api/account/farms', $payload, ['Idempotency-Key' => $key])->assertCreated()->json('data.id');
    $this->postJson('/api/account/farms', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $id);
    $this->assertDatabaseCount('farms', 1);
    $this->postJson('/api/account/farms', array_replace($payload, ['name' => 'Changed']), ['Idempotency-Key' => $key])
        ->assertConflict()->assertJsonPath('message', 'The Idempotency-Key has already been used with a different farm payload.');
    $this->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->assertDatabaseCount('farms', 2);
});

test('creation keys are scoped by owner and can be reused after their bounded retention window', function () {
    $this->freezeTime();
    [$state, $district] = farmWizardRegions();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $key = (string) Str::uuid();
    $payload = farmWizardPayload($state, $district);
    $this->withToken($first->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => $key])->assertCreated();
    app('auth')->forgetGuards();
    $this->withToken($second->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', $payload, ['Idempotency-Key' => $key])->assertCreated();
    $this->travel(25)->hours();
    $this->postJson('/api/account/farms', $payload, ['Idempotency-Key' => $key])->assertCreated();
    expect($first->farms()->count())->toBe(1);
    expect($second->farms()->count())->toBe(2);
});

test('farm listings are private searchable paginated and optionally include map geometry', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $other = Farm::factory()->create(['name' => 'Other private farm']);
    $active = Farm::factory()->for($farmer)->inDistrict($district)->create(['name' => 'Alpha 100% field', 'address' => 'Canal', 'village' => 'Rampura']);
    Farm::factory()->for($farmer)->inDistrict($district)->inactive()->create(['name' => 'Beta field', 'village' => 'Other village']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken);
    $this->getJson('/api/account/farms?per_page=1')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('pagination.total', 2)
        ->assertJsonPath('pagination.last_page', 2)->assertJsonMissingPath('data.0.boundary')->assertJsonMissingPath('data.0.notes');
    $this->getJson('/api/account/farms?status=inactive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
    $this->getJson('/api/account/farms?search=100%25&status=active&per_page=1')->assertOk()
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $active->id);
    $this->getJson('/api/account/farms?search=Canal')->assertOk()->assertJsonPath('pagination.total', 1);
    $this->getJson('/api/account/farms?search=Jaipur')->assertOk()->assertJsonPath('pagination.total', 2);
    $this->getJson('/api/account/farms?search=Other%20private')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0);
    $this->getJson('/api/account/farms?include_boundary=1')->assertOk()->assertJsonPath('data.0.boundary', null);
    $this->getJson('/api/account/farms?page=99')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.from', null);
    $this->getJson('/api/account/farms/'.$other->id)->assertNotFound();
});

test('invalid farm list filters return 422', function (string $query, string $field) {
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)->getJson('/api/account/farms?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['per_page=101', 'per_page'], ['per_page=0', 'per_page'], ['page=0', 'page'], ['status=deleted', 'status'],
    ['include_boundary=wrong', 'include_boundary'], ['search[]=wrong', 'search'],
]);

test('summary converts recorded areas and exposes unit groups when conversion is unavailable', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    Farm::factory()->for($farmer)->inDistrict($district)->create(['area' => '2.000', 'area_unit' => 'ha']);
    Farm::factory()->for($farmer)->inDistrict($district)->inactive()->create(['area' => '3.000', 'area_unit' => 'acre']);
    Farm::factory()->create(['area' => '999.000']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_farms', 2)
        ->assertJsonPath('data.active_farms', 1)->assertJsonPath('data.inactive_farms', 1)
        ->assertJsonPath('data.total_land_area.value', '3.214')->assertJsonPath('data.total_land_area.unit', 'hectare')
        ->assertJsonPath('data.total_land_area_sqm', '32140.569267')->assertJsonPath('data.area_conversion_unavailable', false);
    Farm::factory()->for($farmer)->inDistrict($district)->create(['area' => '10.000', 'area_unit' => 'bigha']);
    $this->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_farms', 3)
        ->assertJsonPath('data.total_land_area.value', null)->assertJsonPath('data.total_land_area_sqm', null)
        ->assertJsonPath('data.total_land_area.unresolved_farms_count', 1)
        ->assertJsonPath('data.area_conversion_unavailable', true)->assertJsonCount(3, 'data.area_totals');
    config()->set('farms.bigha_sqm_by_region', [$district->id => '2500']);
    $this->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_land_area.value', null);
});

test('unresolved Bigha totals are grouped by unit and an empty owner has zero summary values', function () {
    [$state, $district] = farmWizardRegions();
    $otherState = Region::factory()->under($state->parent)->create();
    $otherDistrict = Region::factory()->under($otherState)->create();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_farms', 0)
        ->assertJsonPath('data.total_land_area.value', '0.000')->assertJsonPath('data.total_land_area.unit', 'hectare');
    Farm::factory()->for($farmer)->inDistrict($district)->create(['area' => '10.000', 'area_unit' => 'bigha']);
    Farm::factory()->for($farmer)->inDistrict($otherDistrict)->create(['area' => '10.000', 'area_unit' => 'bigha']);
    config()->set('farms.bigha_sqm_by_region', [$district->id => '2500', $otherDistrict->id => '1000']);
    $this->getJson('/api/account/farms/summary')->assertOk()->assertJsonPath('data.total_land_area.value', null)
        ->assertJsonPath('data.total_land_area.unresolved_farms_count', 2)
        ->assertJsonPath('data.area_totals.0.value', '20.000')->assertJsonCount(1, 'data.area_totals');
});

test('farm edits are partial and keep recorded area separate from derived area', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken);
    $id = $this->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => farmWizardBoundary(), 'boundary_source' => 'manual']),
        ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    $before = Farm::findOrFail($id);
    $response = $this->patchJson('/api/account/farms/'.$id, ['name' => 'Renamed', 'area' => '20.000', 'area_unit' => 'acre',
        'user_id' => 999, 'actual_area_sqm' => 1, 'actual_area' => 1, 'created_at' => '2000-01-01', 'is_active' => false])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.area', '20.000')
        ->assertJsonPath('data.actual_area_unit', 'acre')->assertJsonPath('data.actual_area', '3.05529640')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.state_id', $state->id);
    expect((float) $response->json('data.actual_area_sqm'))->toEqualWithDelta((float) $before->actual_area_sqm, 0.00006);
    $this->assertDatabaseHas('farms', ['id' => $id, 'user_id' => $farmer->id, 'area' => 20]);
    $this->patchJson('/api/account/farms/'.$id, ['latitude' => 50, 'longitude' => 50])
        ->assertUnprocessable()->assertJsonValidationErrors('latitude');
    $this->patchJson('/api/account/farms/'.$id, ['area' => 0])->assertUnprocessable()->assertJsonValidationErrors('area');
});

test('saved inactive farm locations may remain selected but new contradictory locations fail', function () {
    [$state, $district, $tehsil] = farmWizardRegions();
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->inDistrict($district)->create(['tehsil_id' => $tehsil->id, 'region_id' => $tehsil->id]);
    $district->update(['is_active' => false]);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id, ['village' => 'New village'])->assertOk()->assertJsonPath('data.village', 'New village');
    $otherDistrict = Region::factory()->under($state)->create();
    $this->patchJson('/api/account/farms/'.$farm->id, ['district_id' => $otherDistrict->id])
        ->assertUnprocessable()->assertJsonValidationErrors('tehsil_id');
    $this->patchJson('/api/account/farms/'.$farm->id, ['district_id' => $otherDistrict->id, 'tehsil_id' => null])
        ->assertOk()->assertJsonPath('data.region_id', $otherDistrict->id)->assertJsonPath('data.tehsil', null);
});

test('additional details update the same farm and can clear nullable values', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/details', [
            'khasra_number' => ' 123/4 ', 'khata_number' => '12', 'patwari_halka_number' => '45',
            'soil_type' => 'loamy', 'land_type' => 'irrigated', 'farmland_type' => 'arable',
            'water_availability' => 'seasonal', 'irrigation_source' => 'canal', 'notes' => ' खेत की जानकारी ',
            'area' => 999, 'is_active' => false, 'boundary' => farmWizardBoundary(),
        ])->assertOk()->assertJsonPath('data.khasra_number', '123/4')->assertJsonPath('data.notes', 'खेत की जानकारी')
        ->assertJsonPath('data.area', '1.000')->assertJsonPath('data.is_active', true)->assertJsonPath('data.boundary', null);
    $this->patchJson('/api/account/farms/'.$farm->id.'/details', ['notes' => '  ', 'khasra_number' => null])
        ->assertOk()->assertJsonPath('data.notes', null)->assertJsonPath('data.khasra_number', null)->assertJsonPath('data.khata_number', '12');
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'soil_type' => 'loamy', 'notes' => null]);
    $this->assertDatabaseCount('farms', 1);
});

test('additional details reject unsupported classifications and excessive text', function (string $field, mixed $value) {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/details', [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseHas('farms', ['id' => $farm->id, $field => null]);
})->with([
    ['khasra_number', str_repeat('x', 101)], ['notes', str_repeat('x', 5001)], ['soil_type', 'invented'],
    ['land_type', 'invented'], ['farmland_type', 'invented'], ['water_availability', 'invented'], ['irrigation_source', 'invented'],
]);

test('boundaries can be replaced switched to GPS and removed while government area and land records remain', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['area' => '10.000', 'khasra_number' => '123']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken);
    $manual = $this->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => farmWizardBoundary(), 'boundary_source' => 'manual'])
        ->assertOk()->assertJsonPath('data.has_boundary', true)->json('data.actual_area_sqm');
    $larger = farmWizardBoundary();
    $larger['coordinates'][0][0][1][0] = 0.002;
    $larger['coordinates'][0][0][2][0] = 0.002;
    $gps = $this->patchJson('/api/account/farms/'.$farm->id.'/boundary', [
        'boundary' => $larger, 'boundary_source' => 'gps', 'gps_accuracy_m' => '4.80', 'area' => 999,
    ])->assertOk()->assertJsonPath('data.gps_accuracy_m', '4.80')->assertJsonPath('data.area', '10.000')
        ->assertJsonPath('data.khasra_number', '123')->json('data.actual_area_sqm');
    expect((float) $gps)->toBeGreaterThan((float) $manual * 1.99);
    $this->getJson('/api/account/farms?include_boundary=1')->assertOk()->assertJsonPath('data.0.boundary.coordinates.0.0.1', [0.002, 0]);
    $this->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => null])
        ->assertOk()->assertJsonPath('data.boundary', null)->assertJsonPath('data.has_boundary', false)
        ->assertJsonPath('data.boundary_source', null)->assertJsonPath('data.actual_area', null)
        ->assertJsonPath('data.actual_area_unit', null)->assertJsonPath('data.actual_area_sqm', null)
        ->assertJsonPath('data.gps_accuracy_m', null)->assertJsonPath('data.area', '10.000')
        ->assertJsonPath('data.khasra_number', '123')->assertJsonPath('data.latitude', '0.0005000');
    $this->patchJson('/api/account/farms/'.$farm->id.'/boundary', [])->assertUnprocessable()->assertJsonValidationErrors('boundary');
    $this->assertDatabaseHas('farms', ['id' => $farm->id, 'boundary' => null, 'actual_area' => null, 'actual_area_unit' => null, 'gps_accuracy_m' => null]);
});

test('farm status changes preserve farm records and optional fields', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create(['khasra_number' => '123']);
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/status', ['is_active' => false, 'area' => 999])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.area', '1.000')->assertJsonPath('data.khasra_number', '123');
    $this->patchJson('/api/account/farms/'.$farm->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->patchJson('/api/account/farms/'.$farm->id.'/status', ['is_active' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->patchJson('/api/account/farms/'.$farm->id.'/status', [])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->assertDatabaseCount('farms', 1);
});

test('region hierarchy is rechecked under the creation transaction', function () {
    [$state, $district] = farmWizardRegions();
    $otherState = Region::factory()->under($state->parent)->create();
    $farmer = User::factory()->create();
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed, $district, $otherState): void {
        if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "regions"')) {
            $changed = true;
            $district->update(['parent_id' => $otherState->id]);
        }
    });
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district), ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('district_id');
    expect($changed)->toBeTrue();
    $this->assertDatabaseCount('farms', 0);
});

test('spatial persistence failure rolls back the farm insert and returns a safe 500', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    DB::unprepared("CREATE TRIGGER fail_farm_boundary BEFORE UPDATE ON farms WHEN NEW.boundary IS NOT NULL BEGIN SELECT RAISE(ABORT, 'forced spatial failure'); END");
    Log::spy();
    try {
        $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
            ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => farmWizardBoundary(), 'boundary_source' => 'manual']),
                ['Idempotency-Key' => (string) Str::uuid()])->assertInternalServerError()->assertExactJson([
                    'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unable to create the farm. Please try again later.',
                ]);
        $this->assertDatabaseCount('farms', 0);
        Log::shouldHaveReceived('error')->once()->with('Farm database operation failed.',
            Mockery::on(fn (array $context): bool => array_keys($context) === ['exception_type', 'sql_state']
                && $context['exception_type'] === QueryException::class && preg_match('/^[A-Z0-9]{5}$/', $context['sql_state']) === 1));
    } finally {
        DB::unprepared('DROP TRIGGER fail_farm_boundary');
    }
});

test('farms without boundaries can save an explicit map marker', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $response = $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['latitude' => '26.7601234', 'longitude' => '75.7801234']),
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
        ->assertJsonPath('data.latitude', '26.7601234')->assertJsonPath('data.longitude', '75.7801234')
        ->assertJsonPath('data.has_boundary', false)->assertJsonPath('data.boundary', null)->assertJsonPath('data.actual_area_sqm', null);
    $this->assertDatabaseHas('farms', ['id' => $response->json('data.id'), 'latitude' => '26.7601234', 'longitude' => '75.7801234', 'boundary' => null]);
});

test('an area outside database precision returns 422 without inserting a farm', function () {
    [$state, $district] = farmWizardRegions();
    $farmer = User::factory()->create();
    $boundary = ['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [20, 0], [20, 20], [0, 20], [0, 0]]]]];
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farms', farmWizardPayload($state, $district, ['boundary' => $boundary, 'boundary_source' => 'manual']),
            ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('boundary');
    $this->assertDatabaseCount('farms', 0);
});

test('translated region search uses the same locale and fallback as the returned names', function () {
    [$state, $district] = farmWizardRegions();
    $state->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    $farmer = User::factory()->create();
    Farm::factory()->for($farmer)->inDistrict($district)->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/account/farms?search='.rawurlencode('राजस्थान'))->assertOk()
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.state.name', 'राजस्थान');
    $this->getJson('/api/account/farms?search=Jaipur')->assertOk()->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('data.0.district.name', 'Jaipur');
    $this->getJson('/api/account/farms?search=Rajasthan')->assertOk()->assertJsonPath('pagination.total', 0);
});

test('a failed replacement keeps the previous geometry and all derived values intact', function () {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => farmWizardBoundary(), 'boundary_source' => 'gps', 'gps_accuracy_m' => '4.80'])->assertOk();
    $before = $farm->fresh()->getRawOriginal();
    DB::unprepared("CREATE TRIGGER fail_farm_replacement BEFORE UPDATE ON farms BEGIN SELECT RAISE(ABORT, 'forced replacement failure'); END");
    Log::spy();
    try {
        $this->patchJson('/api/account/farms/'.$farm->id.'/boundary', ['boundary' => null])->assertInternalServerError()
            ->assertJsonPath('message', 'Unable to update the farm boundary. Please try again later.');
        expect($farm->fresh()->getRawOriginal())->toBe($before);
        Log::shouldHaveReceived('error')->once();
    } finally {
        DB::unprepared('DROP TRIGGER fail_farm_replacement');
    }
});

test('another farmer receives 404 before viewing or validating private farm data', function (string $method, string $suffix) {
    $farmer = User::factory()->create();
    $other = Farm::factory()->create(['notes' => 'Private farm information']);
    $before = $other->fresh()->getRawOriginal();
    $this->withToken($farmer->createToken('farm', ['frontend'])->plainTextToken)
        ->json($method, '/api/account/farms/'.$other->id.$suffix, [])->assertNotFound()
        ->assertJsonPath('data', null)->assertJsonPath('status', false);
    expect($other->fresh()->getRawOriginal())->toBe($before);
})->with([['GET', ''], ['PATCH', ''], ['PATCH', '/details'], ['PATCH', '/boundary'], ['PATCH', '/status']]);

test('farm endpoints require an active farmer bearer token with frontend ability', function (string $identity, int $code) {
    $farmer = User::factory()->create();
    $farm = Farm::factory()->for($farmer)->create();
    if ($identity === 'admin') {
        $admin = AdminUser::factory()->create(['id' => $farmer->id, 'email' => $farmer->email]);
        $this->withToken($admin->createToken('admin', ['admin', 'frontend'])->plainTextToken);
    } elseif ($identity !== 'guest') {
        $this->withToken($farmer->createToken('farm', $identity === 'wrong ability' ? ['admin'] : ['frontend'])->plainTextToken);
        if ($identity === 'inactive') {
            $farmer->update(['status' => false]);
        } elseif ($identity === 'deleted') {
            $farmer->delete();
        }
    }
    foreach ([['GET', ''], ['GET', '/summary'], ['POST', ''], ['GET', '/'.$farm->id],
        ['PATCH', '/'.$farm->id], ['PATCH', '/'.$farm->id.'/details'], ['PATCH', '/'.$farm->id.'/boundary'], ['PATCH', '/'.$farm->id.'/status']] as [$method, $suffix]) {
        $this->json($method, '/api/account/farms'.$suffix, [])->assertStatus($code)->assertJsonPath('status', false);
    }
    $this->assertDatabaseCount('farms', 1);
})->with([['guest', 401], ['admin', 401], ['wrong ability', 403], ['inactive', 403], ['deleted', 401]]);

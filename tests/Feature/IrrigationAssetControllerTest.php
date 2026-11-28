<?php

use App\Models\AdminUser;
use App\Models\IrrigationAsset;
use App\Models\User;
use App\Services\Irrigation\IrrigationGeometryService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** @return array{User, string} */
function irrigationFarmer(): array
{
    $farmer = User::factory()->create();

    return [$farmer, $farmer->createToken('irrigation', ['frontend'])->plainTextToken];
}

/** @return array<string, mixed> */
function irrigationSourcePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'Main Borewell', 'asset_type' => 'source', 'source_type' => 'borewell', 'depth_meters' => 120,
        'water_availability' => 'good', 'ownership_type' => 'owned', 'notes' => 'Submersible pump, 7.5 HP.',
        'geometry' => ['type' => 'Point', 'coordinates' => [75.800123, 26.910145]],
    ], $overrides);
}

/** @return array<string, mixed> */
function irrigationPipelinePayload(array $overrides = []): array
{
    return array_replace([
        'name' => 'North Field Pipeline', 'asset_type' => 'pipeline', 'pipeline_type' => 'underground', 'diameter_mm' => 90,
        'ownership_type' => 'owned', 'geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.0, 0.001], [0.001, 0.001]]],
    ], $overrides);
}

test('creates a water source for the authenticated farmer only and returns the standard envelope', function () {
    [$farmer, $token] = irrigationFarmer();
    $other = User::factory()->create();

    $response = $this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationSourcePayload(['user_id' => $other->id]))
        ->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Irrigation asset saved successfully.')
        ->assertJsonPath('data.asset_type', 'source')->assertJsonPath('data.source_type', 'borewell')
        ->assertJsonPath('data.pipeline_type', null)->assertJsonPath('data.depth_meters', '120.00')
        ->assertJsonPath('data.geometry', ['type' => 'Point', 'coordinates' => [75.800123, 26.910145]])
        ->assertJsonPath('data.status', 'operational')->assertJsonPath('data.status_label', 'Operational')
        ->assertJsonPath('data.type_label', 'Borewell')->assertJsonPath('data.ownership_label', 'Self Owned')
        ->assertJsonPath('data.water_availability_label', 'Good')->assertJsonPath('data.length_source', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message'])
        ->and($response->json('data.id'))->toBeString()
        ->and($response->json('data'))->not->toHaveKeys(['user_id', 'deleted_at'])
        ->and($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        ->and(IrrigationAsset::query()->sole()->user_id)->toBe($farmer->id);
});

test('calculates a mapped pipeline length on the server and keeps manual or unknown lengths without a route', function () {
    [, $token] = irrigationFarmer();

    $this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationPipelinePayload())
        ->assertCreated()->assertJsonPath('data.length_meters', '222.39')->assertJsonPath('data.length_source', 'calculated')
        ->assertJsonPath('data.diameter_mm', '90.00')->assertJsonPath('data.type_label', 'Underground Pipeline')
        ->assertJsonPath('data.depth_meters', null)->assertJsonPath('data.water_availability', null);
    $this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationPipelinePayload(['geometry' => null, 'length_meters' => 150.5]))
        ->assertCreated()->assertJsonPath('data.length_meters', '150.50')->assertJsonPath('data.length_source', 'manual');
    $this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationPipelinePayload(['geometry' => null]))
        ->assertCreated()->assertJsonPath('data.length_meters', null)->assertJsonPath('data.length_source', null);
});

test('denies guests, admin tokens and tokens without the frontend ability', function (string $identity, int $status) {
    $farmer = User::factory()->create();
    if ($identity === 'admin') {
        $admin = AdminUser::factory()->create(['id' => $farmer->id, 'email' => $farmer->email]);
        $this->withToken($admin->createToken('admin', ['admin', 'frontend'])->plainTextToken);
    } elseif ($identity === 'wrong ability') {
        $this->withToken($farmer->createToken('irrigation', ['admin'])->plainTextToken);
    }

    $this->getJson('/api/account/irrigation-assets')->assertStatus($status)->assertJsonPath('status', false);
    $this->getJson('/api/account/irrigation-assets/summary')->assertStatus($status);
    $this->postJson('/api/account/irrigation-assets', irrigationSourcePayload())->assertStatus($status);
    expect(IrrigationAsset::query()->count())->toBe(0);
})->with([['guest', 401], ['admin', 401], ['wrong ability', 403]]);

test('another farmers asset is not found and never appears in the list, map or summary', function () {
    [$farmer, $token] = irrigationFarmer();
    $own = IrrigationAsset::factory()->for($farmer)->mapped()->create(['name' => 'Own well']);
    $foreign = IrrigationAsset::factory()->pipeline()->mapped()->create(['name' => 'Foreign line']);
    $deleted = IrrigationAsset::factory()->for($farmer)->mapped()->create(['name' => 'Deleted well']);
    $deleted->delete();

    foreach ([$foreign, $deleted] as $hidden) {
        $url = '/api/account/irrigation-assets/'.$hidden->id;
        $this->withToken($token)->getJson($url)->assertNotFound()->assertJsonPath('status', false)->assertJsonPath('data', null);
        $this->withToken($token)->patchJson($url, ['name' => 'Taken over'])->assertNotFound();
        $this->withToken($token)->deleteJson($url)->assertNotFound();
    }
    $foreign->refresh();
    expect($foreign->name)->toBe('Foreign line')->and($foreign->deleted_at)->toBeNull();

    $this->withToken($token)->getJson('/api/account/irrigation-assets')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', (string) $own->id)->assertJsonPath('pagination.total', 1);
    $this->withToken($token)->getJson('/api/account/irrigation-assets/map')->assertOk()
        ->assertJsonCount(1, 'data.features')->assertJsonPath('data.features.0.id', (string) $own->id);
    $this->withToken($token)->getJson('/api/account/irrigation-assets/summary')->assertOk()
        ->assertJsonPath('data.total_assets', 1)->assertJsonPath('data.pipelines', 0)->assertJsonPath('data.mapped_assets', 1);
});

test('rejects invalid assets with field errors and saves nothing', function (string $category, array $overrides, string $field) {
    [, $token] = irrigationFarmer();
    $payload = $category === 'source' ? irrigationSourcePayload($overrides) : irrigationPipelinePayload($overrides);

    $this->withToken($token)->postJson('/api/account/irrigation-assets', $payload)
        ->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonValidationErrors([$field]);
    expect(IrrigationAsset::query()->count())->toBe(0);
})->with([
    'blank name' => ['source', ['name' => '   '], 'name'],
    '151 character name' => ['source', ['name' => str_repeat('a', 151)], 'name'],
    'unknown category' => ['source', ['asset_type' => 'well'], 'asset_type'],
    'unknown source type' => ['source', ['source_type' => 'lake'], 'source_type'],
    'missing source type' => ['source', ['source_type' => null], 'source_type'],
    'pipeline type on a source' => ['source', ['pipeline_type' => 'surface'], 'pipeline_type'],
    'unknown status' => ['source', ['status' => 'broken'], 'status'],
    'depth on a canal' => ['source', ['source_type' => 'canal', 'depth_meters' => 5], 'depth_meters'],
    'diameter on a source' => ['source', ['diameter_mm' => 90], 'diameter_mm'],
    'length on a source' => ['source', ['length_meters' => 90], 'length_meters'],
    'zero depth' => ['source', ['depth_meters' => 0], 'depth_meters'],
    'three decimals' => ['source', ['depth_meters' => 1.234], 'depth_meters'],
    'depth too large' => ['source', ['depth_meters' => 100000000], 'depth_meters'],
    'availability on a pipeline' => ['pipeline', ['water_availability' => 'good'], 'water_availability'],
    'depth on a pipeline' => ['pipeline', ['depth_meters' => 3], 'depth_meters'],
    'missing pipeline type' => ['pipeline', ['pipeline_type' => null], 'pipeline_type'],
    'manual length with a route' => ['pipeline', ['length_meters' => 50], 'length_meters'],
    'source with a line' => ['source', ['geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.0, 1.0]]]], 'geometry'],
    'pipeline with a point' => ['pipeline', ['geometry' => ['type' => 'Point', 'coordinates' => [0.0, 0.0]]], 'geometry'],
    'single point route' => ['pipeline', ['geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0]]]], 'geometry'],
    'identical points' => ['pipeline', ['geometry' => ['type' => 'LineString', 'coordinates' => [[1.0, 1.0], [1.0, 1.0], [1.0, 1.0]]]], 'geometry'],
    'consecutive duplicate' => ['pipeline', ['geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.0, 1.0], [0.0, 1.0]]]], 'geometry'],
    'over 1,000 points' => ['pipeline', ['geometry' => ['type' => 'LineString',
        'coordinates' => array_map(fn (int $index): array => [0.0, $index / 10000], range(0, 1000))]], 'geometry'],
    'latitude out of range' => ['source', ['geometry' => ['type' => 'Point', 'coordinates' => [75.8, 91.0]]], 'geometry'],
    'longitude out of range' => ['source', ['geometry' => ['type' => 'Point', 'coordinates' => [181.0, 26.9]]], 'geometry'],
    'text coordinates' => ['source', ['geometry' => ['type' => 'Point', 'coordinates' => ['75.8', '26.9']]], 'geometry'],
    'three coordinates' => ['source', ['geometry' => ['type' => 'Point', 'coordinates' => [75.8, 26.9, 300.0]]], 'geometry'],
    'extra geometry keys' => ['source', ['geometry' => ['type' => 'Point', 'coordinates' => [75.8, 26.9], 'crs' => 'EPSG:3857']], 'geometry'],
    'well known text' => ['source', ['geometry' => 'POINT(75.8 26.9)'], 'geometry'],
]);

test('returns validation messages in Hindi for the Hindi locale', function () {
    [, $token] = irrigationFarmer();

    // Field rules (form request) and record rules (service) both answer in Hindi.
    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->postJson('/api/account/irrigation-assets', irrigationSourcePayload(['name' => '']))
        ->assertUnprocessable()->assertJsonPath('errors.name.0', 'नाम दर्ज करें।');
    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->postJson('/api/account/irrigation-assets', irrigationSourcePayload(['source_type' => 'canal', 'depth_meters' => 5]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.depth_meters.0', 'गहराई केवल बोरवेल, कुएँ, तालाब और जलाशय के लिए दर्ज की जा सकती है।');
});

test('patch keeps omitted values, clears explicit nulls and manages the route length', function () {
    [, $token] = irrigationFarmer();
    $id = $this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationPipelinePayload(['notes' => 'Approximate route.']))
        ->assertCreated()->json('data.id');
    $url = '/api/account/irrigation-assets/'.$id;

    $this->withToken($token)->patchJson($url, ['name' => 'Renamed line'])->assertOk()
        ->assertJsonPath('data.name', 'Renamed line')->assertJsonPath('data.notes', 'Approximate route.')
        // JSON has no "0.0": whole-number coordinates come back as integers, like ST_AsGeoJSON prints them.
        ->assertJsonPath('data.geometry.coordinates', [[0, 0], [0, 0.001], [0.001, 0.001]])
        ->assertJsonPath('data.length_meters', '222.39');
    $this->withToken($token)->patchJson($url, ['notes' => null, 'ownership_type' => null])->assertOk()
        ->assertJsonPath('data.notes', null)->assertJsonPath('data.ownership_type', null)->assertJsonPath('data.diameter_mm', '90.00');
    $this->withToken($token)->patchJson($url, ['length_meters' => 75])->assertUnprocessable()->assertJsonValidationErrors(['length_meters']);
    $this->withToken($token)->patchJson($url, ['geometry' => null])->assertOk()
        ->assertJsonPath('data.geometry', null)->assertJsonPath('data.length_meters', null)->assertJsonPath('data.length_source', null);
    $this->withToken($token)->patchJson($url, ['length_meters' => 180])->assertOk()
        ->assertJsonPath('data.length_meters', '180.00')->assertJsonPath('data.length_source', 'manual');
    $this->withToken($token)->patchJson($url, ['geometry' => ['type' => 'LineString', 'coordinates' => [[0.0, 0.0], [0.0, 0.001]]]])->assertOk()
        ->assertJsonPath('data.length_meters', '111.20')->assertJsonPath('data.length_source', 'calculated');
    $this->withToken($token)->patchJson($url, ['geometry' => null, 'length_meters' => 95.5])->assertOk()
        ->assertJsonPath('data.geometry', null)->assertJsonPath('data.length_meters', '95.50')->assertJsonPath('data.length_source', 'manual');
    $this->withToken($token)->patchJson($url, ['asset_type' => 'source'])->assertUnprocessable()
        ->assertJsonPath('errors.asset_type.0', 'The category can’t be changed after creation.');
    $this->withToken($token)->patchJson($url, ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive')
        ->assertJsonPath('data.name', 'Renamed line')->assertJsonPath('data.length_meters', '95.50');
});

test('switching to a source type without depth needs the old depth cleared in the same request', function () {
    [, $token] = irrigationFarmer();
    $url = '/api/account/irrigation-assets/'.$this->withToken($token)->postJson('/api/account/irrigation-assets', irrigationSourcePayload())->json('data.id');

    $this->withToken($token)->patchJson($url, ['source_type' => 'canal'])->assertUnprocessable()->assertJsonValidationErrors(['depth_meters']);
    $this->withToken($token)->patchJson($url, ['source_type' => 'canal', 'depth_meters' => null])->assertOk()
        ->assertJsonPath('data.depth_meters', null)->assertJsonPath('data.type_label', 'Canal')
        ->assertJsonPath('data.geometry.coordinates', [75.800123, 26.910145]);
    $this->withToken($token)->patchJson($url, ['geometry' => null])->assertOk()->assertJsonPath('data.geometry', null);
    $this->withToken($token)->patchJson($url, ['geometry' => ['type' => 'Point', 'coordinates' => [75.81, 26.92]]])->assertOk()
        ->assertJsonPath('data.geometry', ['type' => 'Point', 'coordinates' => [75.81, 26.92]]);
});

test('summary counts every non deleted asset including inactive ones and ignores filters', function () {
    [$farmer, $token] = irrigationFarmer();
    $summary = fn () => $this->withToken($token)->getJson('/api/account/irrigation-assets/summary?asset_type=pipeline&status=inactive')->assertOk();

    expect($summary()->json('data'))->toBe(['total_assets' => 0, 'water_sources' => 0, 'water_sources_needing_attention' => 0,
        'pipelines' => 0, 'pipelines_with_route' => 0, 'total_pipeline_length_meters' => '0.00', 'pipelines_with_known_length' => 0,
        'pipelines_with_unknown_length' => 0, 'mapped_assets' => 0, 'unmapped_assets' => 0]);

    IrrigationAsset::factory()->for($farmer)->mapped()->create(['status' => 'inactive']);
    IrrigationAsset::factory()->for($farmer)->create(['source_type' => 'tank', 'depth_meters' => null, 'status' => 'needs_maintenance']);
    IrrigationAsset::factory()->for($farmer)->pipeline()->create();
    expect($summary()->json('data.total_pipeline_length_meters'))->toBeNull()
        ->and($summary()->json('data.pipelines_with_unknown_length'))->toBe(1);

    // A pipeline out of service is not a water source needing attention.
    IrrigationAsset::factory()->for($farmer)->pipeline()->create(['length_meters' => '150.00', 'status' => 'out_of_service']);
    IrrigationAsset::factory()->for($farmer)->pipeline()->mapped()->create();
    IrrigationAsset::factory()->for($farmer)->pipeline()->create(['length_meters' => '40.00'])->delete();
    IrrigationAsset::factory()->pipeline()->create(['length_meters' => '999.00']);
    $mappedLength = (new IrrigationGeometryService)->lengthMeters([[75.8001, 26.9101], [75.8011, 26.9111]]);

    expect($mappedLength)->toBe('148.98')
        ->and($summary()->json('data'))->toBe(['total_assets' => 5, 'water_sources' => 2, 'water_sources_needing_attention' => 1,
            'pipelines' => 3, 'pipelines_with_route' => 1, 'total_pipeline_length_meters' => '298.98', 'pipelines_with_known_length' => 2,
            'pipelines_with_unknown_length' => 1, 'mapped_assets' => 2, 'unmapped_assets' => 3]);
});

test('lists assets newest first with filters, label search in both languages and pagination', function () {
    [$farmer, $token] = irrigationFarmer();
    IrrigationAsset::factory()->for($farmer)->create(['name' => 'Old Open Well', 'source_type' => 'open_well',
        'status' => 'needs_maintenance', 'updated_at' => now()->subDays(3)]);
    IrrigationAsset::factory()->for($farmer)->create(['name' => 'Village outlet', 'source_type' => 'canal',
        'depth_meters' => null, 'updated_at' => now()->subDays(2)]);
    IrrigationAsset::factory()->for($farmer)->pipeline()->create(['name' => 'Feeder 50%_line', 'pipeline_type' => 'surface',
        'updated_at' => now()->subDay()]);
    IrrigationAsset::factory()->for($farmer)->create(['name' => 'Spare tank', 'source_type' => 'tank', 'depth_meters' => null,
        'status' => 'inactive']);
    $names = fn (array $query = []): array => array_column($this->withToken($token)
        ->getJson('/api/account/irrigation-assets?'.http_build_query($query))->assertOk()->json('data'), 'name');

    $this->withToken($token)->getJson('/api/account/irrigation-assets')
        ->assertJsonPath('pagination.per_page', 20)->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.from', 1);
    expect($names())->toBe(['Spare tank', 'Feeder 50%_line', 'Village outlet', 'Old Open Well'])
        ->and($names(['asset_type' => 'pipeline']))->toBe(['Feeder 50%_line'])
        ->and($names(['asset_type' => 'source', 'source_type' => 'canal']))->toBe(['Village outlet'])
        ->and($names(['status' => 'inactive']))->toBe(['Spare tank'])
        ->and($names(['search' => 'नहर']))->toBe(['Village outlet'])
        ->and($names(['search' => 'open well']))->toBe(['Old Open Well'])
        ->and($names(['search' => 'surface']))->toBe(['Feeder 50%_line'])
        ->and($names(['search' => '50%']))->toBe(['Feeder 50%_line'])
        ->and($names(['search' => '_']))->toBe(['Feeder 50%_line'])
        ->and($names(['search' => 'nothing like this']))->toBe([])
        ->and($names(['per_page' => 2, 'page' => 2]))->toBe(['Village outlet', 'Old Open Well']);

    foreach ([['asset_type' => 'source', 'pipeline_type' => 'surface'], ['asset_type' => 'pipeline', 'source_type' => 'canal'],
        ['source_type' => 'canal', 'pipeline_type' => 'surface'], ['per_page' => 101], ['search' => str_repeat('a', 101)], ['status' => 'lost']] as $query) {
        $this->withToken($token)->getJson('/api/account/irrigation-assets?'.http_build_query($query))->assertUnprocessable();
    }
});

test('the map returns mapped assets only, as GeoJSON features with minimal properties and the list filters', function () {
    [$farmer, $token] = irrigationFarmer();
    $source = IrrigationAsset::factory()->for($farmer)->mapped()->create(['name' => 'Mapped well', 'notes' => 'Private note']);
    $pipeline = IrrigationAsset::factory()->for($farmer)->pipeline()->mapped()->create(['name' => 'Mapped line']);
    IrrigationAsset::factory()->for($farmer)->create(['name' => 'Unmapped well']);

    $map = $this->withToken($token)->getJson('/api/account/irrigation-assets/map?page=2')->assertOk()
        ->assertJsonPath('data.type', 'FeatureCollection')->assertJsonPath('pagination', null)->assertJsonCount(2, 'data.features');
    expect($map->json('data.features.0'))->toBe(['type' => 'Feature', 'id' => (string) $source->id,
        'geometry' => ['type' => 'Point', 'coordinates' => [75.8001, 26.9101]],
        'properties' => ['name' => 'Mapped well', 'asset_type' => 'source', 'source_type' => 'borewell', 'status' => 'operational',
            'type_label' => 'Borewell', 'status_label' => 'Operational']])
        ->and($map->json('data.features.1.properties.pipeline_type'))->toBe('underground')
        ->and($map->json('data.features.1.geometry.coordinates'))->toBe([[75.8001, 26.9101], [75.8011, 26.9111]]);
    $this->withToken($token)->getJson('/api/account/irrigation-assets/map?asset_type=pipeline')
        ->assertJsonCount(1, 'data.features')->assertJsonPath('data.features.0.id', (string) $pipeline->id);
    $this->withToken($token)->getJson('/api/account/irrigation-assets/map?search=unmapped')->assertJsonCount(0, 'data.features');
});

test('options list every key with labels in the request locale and the source types that record depth', function () {
    [, $token] = irrigationFarmer();

    $options = $this->withToken($token)->getJson('/api/account/irrigation-assets/options')->assertOk()->json('data');
    expect(array_column($options['source_types'], 'key'))->toBe(['borewell', 'open_well', 'canal', 'pond', 'tank', 'river',
        'reservoir', 'rainwater_collection', 'other'])
        ->and(array_column(array_filter($options['source_types'], fn (array $type): bool => $type['supports_depth']), 'key'))
        ->toBe(['borewell', 'open_well', 'pond', 'reservoir'])
        ->and(array_column($options['pipeline_types'], 'key'))->toBe(['underground', 'surface', 'portable', 'other'])
        ->and(array_column($options['statuses'], 'key'))->toBe(['operational', 'needs_maintenance', 'out_of_service', 'inactive'])
        ->and(array_column($options['ownership_types'], 'label'))->toBe(['Self Owned', 'Shared', 'Rented', 'Government / Public', 'Other'])
        ->and(array_column($options['water_availability'], 'key'))->toBe(['good', 'moderate', 'low', 'seasonal', 'no_water', 'unknown'])
        ->and(array_column($options['asset_types'], 'label'))->toBe(['Water Source', 'Pipeline']);

    $hindi = $this->withToken($token)->withHeader('X-Locale', 'hi')->getJson('/api/account/irrigation-assets/options')->json('data');
    expect($hindi['source_types'][0]['label'])->toBe('बोरवेल / ट्यूबवेल')
        ->and($hindi['statuses'][0]['label'])->toBe('चालू')
        ->and($hindi['pipeline_types'][2]['label'])->toBe('चलित पाइपलाइन');
});

test('delete soft deletes only that asset and returns the standard envelope', function () {
    [$farmer, $token] = irrigationFarmer();
    $asset = IrrigationAsset::factory()->for($farmer)->mapped()->create();
    $kept = IrrigationAsset::factory()->for($farmer)->pipeline()->create(['status' => 'inactive']);

    $this->withToken($token)->deleteJson('/api/account/irrigation-assets/'.$asset->id)->assertOk()
        ->assertExactJson(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Irrigation asset deleted successfully.']);
    expect(IrrigationAsset::withTrashed()->find($asset->id)->deleted_at)->not->toBeNull()
        ->and($kept->fresh()->deleted_at)->toBeNull();
    $this->withToken($token)->getJson('/api/account/irrigation-assets')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'inactive');
    $this->withToken($token)->getJson('/api/account/irrigation-assets/map')->assertJsonCount(0, 'data.features');
    $this->withToken($token)->getJson('/api/account/irrigation-assets/summary')->assertJsonPath('data.total_assets', 1);
    $this->withToken($token)->deleteJson('/api/account/irrigation-assets/'.$asset->id)->assertNotFound();
});

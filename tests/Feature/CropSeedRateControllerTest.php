<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use App\Models\Seed;
use App\Models\SeedImage;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function cropRatePayload(Crop $crop, array $overrides = []): array
{
    $sourceId = array_key_exists('source_id', $overrides) ? $overrides['source_id'] : KnowledgeSource::factory()->active()->create()->id;

    return array_replace([
        'crop_id' => $crop->id, 'material_type' => 'seed', 'rate_basis' => 'per_area',
        'rate_recommended' => '4.0000', 'rate_unit_code' => 'kg', 'area_unit_code' => 'ha', 'region_scope' => 'all',
        'source_id' => $sourceId,
    ], $overrides);
}

function cropRateDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('crop level recommendations create with decimal strings source and no fabricated seed or spacing', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $payload = cropRatePayload($crop);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', $payload)
        ->assertCreated()->assertJsonPath('data.rate_recommended', '4.0000')->assertJsonPath('data.crop_name', 'Mustard')
        ->assertJsonPath('data.rate_min', null)->assertJsonPath('data.rate_max', null)->assertJsonPath('data.spacing_row_cm', null)
        ->assertJsonPath('data.seed', null)->assertJsonPath('data.season', null)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.region_ids', [])->assertJsonPath('data.regions', [])->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('crop_seed_rates', ['id' => $response->json('data.id'), 'source_id' => $payload['source_id'], 'crop_id' => $crop->id]);
    expect($crop->seedRates()->sole()->id)->toBe($response->json('data.id'));
    expect(KnowledgeSource::findOrFail($payload['source_id'])->seedRates()->sole()->id)->toBe($response->json('data.id'));
    $this->assertDatabaseCount('crop_seed_rate_regions', 0);
});

test('variety and seed level recommendations preserve valid dependent relationships', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $seed = $kind === 'variety' ? null : Seed::factory()->for($crop)->completed()->create(['is_active' => true, 'verification_status' => 'verified',
        'crop_variety_id' => $kind === 'generic seed' ? null : $variety->id, 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    $payload = cropRatePayload($crop, ['crop_variety_id' => $variety->id, 'seed_id' => $seed?->id, 'season_id' => $season->id,
        'rate_min' => '0', 'rate_max' => '5.5000', 'spacing_row_cm' => '30.25', 'spacing_plant_cm' => '10.00']);
    $id = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', $payload)
        ->assertCreated()->assertJsonPath('data.crop_variety_id', $variety->id)->assertJsonPath('data.seed_id', $seed?->id)
        ->assertJsonPath('data.rate_min', '0.0000')->assertJsonPath('data.rate_max', '5.5000')
        ->assertJsonPath('data.spacing_row_cm', '30.25')->json('data.id');
    expect($variety->seedRates()->sole()->id)->toBe($id);
    expect($season->seedRates()->sole()->id)->toBe($id);
    if ($seed !== null) {
        expect($seed->seedRates()->sole()->id)->toBe($id);
    }
})->with(['variety', 'seed', 'generic seed']);

test('crop seed rate endpoints reject unauthenticated requests with 401', function (string $method, string $suffix) {
    $rate = CropSeedRate::factory()->create();
    $this->json($method, '/api/admin/crop-seed-rates'.str_replace('{id}', (string) $rate->id, $suffix))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'], 'update' => ['PATCH', '/{id}'], 'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('farmers inactive admins and missing admin abilities are rejected', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'ability']);

test('crop seed rates enforce the existing seed management permission', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-seeds', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates')->assertForbidden();
});

test('required recommendation fields and source cannot be omitted', function () {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['crop_id', 'material_type', 'rate_basis', 'rate_recommended', 'rate_unit_code', 'area_unit_code', 'region_scope', 'source_id']);
    $this->assertDatabaseCount('crop_seed_rates', 0);
});

test('invalid primitive fields return 422 before any rates or mappings persist', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', cropRatePayload($crop, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($response->json('errors.'.$field.'.0'))->toBeString()->not->toBe('');
    $this->assertDatabaseCount('crop_seed_rates', 0);
    $this->assertDatabaseCount('crop_seed_rate_regions', 0);
})->with([
    'invalid crop' => ['crop_id', 999999], 'crop array' => ['crop_id', []], 'unknown variety' => ['crop_variety_id', 999999],
    'unknown seed' => ['seed_id', 999999], 'unknown season' => ['season_id', 999999], 'material' => ['material_type', 'invalid'],
    'sowing' => ['sowing_method', 'invalid'], 'irrigation' => ['irrigation_condition', 'invalid'], 'basis' => ['rate_basis', 'per_plant'],
    'zero' => ['rate_recommended', 0], 'negative' => ['rate_recommended', -1], 'precision' => ['rate_recommended', '4.12345'],
    'overflow' => ['rate_recommended', '10000000000'], 'scientific notation' => ['rate_recommended', '4e2'],
    'min negative' => ['rate_min', -1], 'min above' => ['rate_min', '4.0001'], 'min precision' => ['rate_min', '1.12345'],
    'max below' => ['rate_max', '3.9999'], 'max precision' => ['rate_max', '5.12345'], 'max overflow' => ['rate_max', '10000000000'],
    'unsupported unit' => ['rate_unit_code', 'sett'], 'nonarea' => ['area_unit_code', 'kg'], 'row zero' => ['spacing_row_cm', 0],
    'row precision' => ['spacing_row_cm', '30.123'], 'spacing negative' => ['spacing_plant_cm', -1], 'spacing overflow' => ['spacing_plant_cm', '100000000'],
    'unknown source' => ['source_id', 999999], 'missing source' => ['source_id', null], 'invalid scope' => ['region_scope', 'specific_regions'],
    'boolean' => ['is_active', 'yes'], 'notes length' => ['notes', str_repeat('a', 65536)], 'notes array' => ['notes', []],
    'legacy rate' => ['seed_rate_kg_ha', 4], 'translations' => ['translations', ['en' => ['name' => 'Unexpected']]],
    'client timestamp' => ['created_at', '2026-11-08'], 'locale' => ['locale', 'hi'],
]);

test('cross crop variety seed and mismatched seed material combinations are rejected', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $otherCrop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($kind === 'other variety crop' ? $otherCrop : $crop)->withNames()->create(['is_active' => true]);
    $seedVariety = $kind === 'different variety' ? CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true])->id : $variety->id;
    $seed = Seed::factory()->for($kind === 'other seed crop' ? $otherCrop : $crop)->completed()->create(['crop_variety_id' => $kind === 'other seed crop' ? null : $seedVariety,
        'is_active' => true, 'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($crop, ['crop_variety_id' => $variety->id, 'seed_id' => $seed->id, 'material_type' => $kind === 'material mismatch' ? 'tuber' : 'seed']))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['other variety crop' => ['other variety crop', 'crop_variety_id'], 'other seed crop' => ['other seed crop', 'seed_id'],
    'different variety' => ['different variety', 'seed_id'], 'material mismatch' => ['material mismatch', 'material_type']]);

test('quantity dimensions follow existing Seed unit compatibility', function (string $material, string $unit, bool $valid) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($crop, ['material_type' => $material, 'rate_unit_code' => $unit, 'area_unit_code' => 'acre']));
    $valid ? $response->assertCreated()->assertJsonPath('data.rate_unit_code', $unit) : $response->assertUnprocessable()->assertJsonValidationErrors('rate_unit_code');
})->with(['g' => ['seed', 'g', true], 'kg tuber' => ['tuber', 'kg', true], 'count tuber' => ['tuber', 'piece', true],
    'grafted plant' => ['grafted_plant', 'plant', true], 'seedling piece' => ['seedling', 'piece', true],
    'mass plant invalid' => ['grafted_plant', 'kg', false], 'plant seed invalid' => ['seed', 'plant', false]]);

test('new source selections require active verified usable scientific references', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $source = KnowledgeSource::factory()->active()->create(match ($kind) {
        'inactive' => ['is_active' => false], 'pending' => ['verification_status' => 'pending'],
        'no reference' => ['source_url' => null, 'reference_identifier' => null],
    });
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', cropRatePayload($crop, ['source_id' => $source->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
})->with(['inactive', 'pending', 'no reference']);

test('specific recommendations save only district mappings across states and expose preselection data', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = cropRateDistrict();
    $second = cropRateDistrict('Indore');
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($crop, ['region_scope' => 'specific', 'region_ids' => [$second->id, $first->id]]))->assertCreated()
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.name', 'Jaipur')
        ->assertJsonPath('data.regions.0.parent_id', $first->parent_id);
    expect($first->cropSeedRates()->sole()->id)->toBe($response->json('data.id'));
    $this->assertDatabaseCount('crop_seed_rate_regions', 2);
});

test('empty invalid duplicate state and new inactive districts return 422', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $district = cropRateDistrict();
    if ($kind === 'inactive') {
        $district->forceFill(['is_active' => false])->save();
    }
    $ids = match ($kind) {
        'empty' => [], 'state' => [$district->parent_id], 'unknown' => [999999], 'duplicate' => [$district->id, $district->id], default => [$district->id]
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($crop, ['region_scope' => $kind === 'all' ? 'all' : 'specific', 'region_ids' => $ids]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('crop_seed_rates', 0);
    $this->assertDatabaseCount('crop_seed_rate_regions', 0);
})->with(['empty' => ['empty', 'region_ids'], 'state' => ['state', 'region_ids.0'], 'unknown' => ['unknown', 'region_ids.0'],
    'duplicate' => ['duplicate', 'region_ids.0'], 'inactive' => ['inactive', 'region_ids.0'], 'all' => ['all', 'region_ids']]);

test('partial edits and scope switches retain remove and reject readding inactive districts correctly', function () {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create(['region_scope' => 'specific']);
    $first = cropRateDistrict();
    $second = cropRateDistrict('Ajmer', $first->parent);
    $third = cropRateDistrict('Alwar', $first->parent);
    $rate->regions()->attach([$first->id, $second->id]);
    $first->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['notes' => 'Partial edit'])
        ->assertOk()->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.is_active', false);
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_ids' => [$first->id, $third->id]])->assertOk();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_ids' => [$third->id]])->assertOk();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_ids' => [$first->id, $third->id]])->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_scope' => 'all'])->assertOk()->assertJsonPath('data.region_ids', []);
    $this->assertDatabaseCount('crop_seed_rate_regions', 0);
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_scope' => 'specific'])->assertUnprocessable()->assertJsonValidationErrors('region_ids');
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_scope' => 'specific', 'region_ids' => [$third->id]])->assertOk()->assertJsonPath('data.region_ids', [$third->id]);
    expect($rate->fresh()->notes)->toBe('Partial edit');
});

test('recommended-only partial changes revalidate existing minimum and maximum independently', function (string $field, string $value) {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create(['rate_min' => '3.0000', 'rate_max' => '5.0000']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['rate_recommended' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($rate->fresh()->rate_recommended)->toBe('4.0000');
})->with(['minimum' => ['rate_min', '2.9999'], 'maximum' => ['rate_max', '5.0001']]);

test('active equally applicable recommendations conflict even if rates or units differ', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $rate = CropSeedRate::factory()->create(['is_active' => true]);
    $payload = cropRatePayload($rate->crop, ['is_active' => true, 'rate_recommended' => $kind === 'different number' ? '5.0000' : '4.0000',
        'rate_unit_code' => $kind === 'different unit' ? 'g' : 'kg', 'area_unit_code' => $kind === 'different area unit' ? 'acre' : 'ha']);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', $payload)->assertConflict();
    expect($response->json('message'))->toContain('rate '.$rate->id.' ');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('crop_seed_rates', 1);
})->with(['duplicate', 'different number', 'different unit', 'different area unit']);

test('broader fallbacks allow variety seed season cultivation and regional overrides', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $rate = CropSeedRate::factory()->create(['is_active' => true]);
    $extra = match ($kind) {
        'variety' => ['crop_variety_id' => CropVariety::factory()->for($rate->crop)->withNames()->create(['is_active' => true])->id],
        'seed' => ['seed_id' => Seed::factory()->for($rate->crop)->completed()->create(['is_active' => true, 'verification_status' => 'verified', 'source_id' => $rate->source_id])->id],
        'season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => true])->id],
        'sowing' => ['sowing_method' => 'line_sowing'], 'irrigation' => ['irrigation_condition' => 'rainfed'],
        'district' => ['region_scope' => 'specific', 'region_ids' => [cropRateDistrict()->id]],
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', cropRatePayload($rate->crop, ['is_active' => true, ...$extra]))->assertCreated();
    $this->assertDatabaseCount('crop_seed_rates', 2);
})->with(['variety', 'seed', 'season', 'sowing', 'irrigation', 'district']);

test('intersecting equally specific district recommendations conflict while disjoint sets coexist', function (bool $shared) {
    $admin = AdminUser::factory()->superUser()->create();
    $rate = CropSeedRate::factory()->create(['region_scope' => 'specific', 'is_active' => true]);
    $first = cropRateDistrict();
    $second = cropRateDistrict('Ajmer', $first->parent);
    $rate->regions()->attach($first);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($rate->crop, ['is_active' => true, 'region_scope' => 'specific', 'region_ids' => [$shared ? $first->id : $second->id]]));
    $shared ? $response->assertConflict() : $response->assertCreated();
})->with(['intersecting' => true, 'disjoint' => false]);

test('redundant variety selection cannot bypass a seed-specific duplicate', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $source = KnowledgeSource::factory()->active()->create();
    $seed = Seed::factory()->for($crop)->completed()->create(['crop_variety_id' => $variety->id, 'is_active' => true, 'verification_status' => 'verified', 'source_id' => $source->id]);
    CropSeedRate::factory()->for($crop)->for($seed)->create(['is_active' => true]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates',
        cropRatePayload($crop, ['seed_id' => $seed->id, 'crop_variety_id' => $variety->id, 'is_active' => true]))->assertConflict();
});

test('activation rechecks conflicts and active edits exclude their own record', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $first = CropSeedRate::factory()->create(['is_active' => true]);
    $second = CropSeedRate::factory()->for($first->crop)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$first->id, ['rate_recommended' => '4.1250'])
        ->assertOk()->assertJsonPath('data.id', $first->id)->assertJsonPath('data.rate_recommended', '4.1250');
    $this->patchJson('/api/admin/crop-seed-rates/'.$second->id.'/status', ['is_active' => true])->assertConflict();
    expect($second->fresh()->is_active)->toBeFalse();
    $this->patchJson('/api/admin/crop-seed-rates/'.$first->id.'/status', ['is_active' => false])->assertOk();
    $this->patchJson('/api/admin/crop-seed-rates/'.$second->id.'/status', ['is_active' => true])->assertOk();
});

test('publication requires reviewer permission and status updates preserve scientific fields and mappings', function () {
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $rate = CropSeedRate::factory()->create(['region_scope' => 'specific', 'notes' => 'Scientific remarks', 'rate_min' => '3.5', 'rate_max' => '4.5']);
    $district = cropRateDistrict();
    $rate->regions()->attach($district);
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', ['is_active' => true])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', ['is_active' => true])->assertOk();
    $district->forceFill(['is_active' => false])->save();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['region_ids' => [$district->id]])->assertOk()->assertJsonPath('data.regions.0.is_active', false);
    app('auth')->forgetGuards();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['notes' => 'Unreviewed'])->assertForbidden();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', ['is_active' => false])->assertOk()
        ->assertJsonPath('data.region_ids', [$district->id])->assertJsonPath('data.rate_min', '3.5000')->assertJsonPath('data.rate_max', '4.5000')
        ->assertJsonPath('data.rate_recommended', '4.0000')->assertJsonPath('data.source_id', $rate->source_id)->assertJsonPath('data.notes', 'Scientific remarks');
});

test('inactive historical parents remain readable but cannot be newly selected or republished', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $rate = CropSeedRate::factory()->create();
    $rate->crop->forceFill(['is_active' => false])->save();
    $rate->knowledgeSource->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates/'.$rate->id)->assertOk();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['notes' => 'Historical correction'])->assertOk();
    $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors(['crop_id', 'source_id']);
    $this->postJson('/api/admin/crop-seed-rates', cropRatePayload($rate->crop))->assertUnprocessable()->assertJsonValidationErrors('crop_id');
});

test('listing searches translated crop variety seed names SKU sowing and literal wildcards', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Brijraj', 'ब्रिजराज')->create(['is_active' => true]);
    $seed = Seed::factory()->for($crop)->completed()->create(['sku' => 'CS-RATE-1', 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
    $seed->translations()->where('locale', 'hi')->update(['name' => 'बीज 100%']);
    $rate = CropSeedRate::factory()->for($crop)->for($variety, 'cropVariety')->for($seed)->create(['sowing_method' => 'line_sowing']);
    CropSeedRate::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-seed-rates?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $rate->id);
})->with(['सरसों', 'ब्रिजराज', 'बीज', 'CS-RATE-1', 'line_sowing', '100%']);

test('each listing filter independently restricts the returned recommendations', function (string $field) {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create();
    $other = CropSeedRate::factory()->for($rate->crop)->create(match ($field) {
        'crop_id' => ['crop_id' => Crop::factory()->published()->create()->id],
        'crop_variety_id' => ['crop_variety_id' => CropVariety::factory()->for($rate->crop)->create()->id],
        'season_id' => ['season_id' => Season::factory()->create()->id], 'material_type' => ['material_type' => 'tuber'],
        'region_scope' => ['region_scope' => 'specific'], 'is_active' => ['is_active' => true],
    });
    $target = in_array($field, ['crop_variety_id', 'season_id'], true) ? $other : $rate;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates?'.http_build_query([$field => $target->{$field}]))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $target->id);
})->with(['crop_id', 'crop_variety_id', 'season_id', 'material_type', 'region_scope', 'is_active']);

test('listing rejects inconsistent crop variety filters and invalid pagination', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates?crop_id='.$crop->id.'&crop_variety_id='.$variety->id)
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    $this->getJson('/api/admin/crop-seed-rates?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
});

test('listing paginates stably with filter links without district maps or image galleries', function () {
    $this->freezeTime();
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $rates = CropSeedRate::factory()->count(3)->for($crop)->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates?crop_id='.$crop->id.'&per_page=2')
        ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $rates[0]->id)
        ->assertJsonMissingPath('data.0.region_ids')->assertJsonMissingPath('data.0.regions');
    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id, 'per_page=2', 'page=2');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rates[2]->id);
});

test('related labels use header locale with English fallback and only the primary image', function (string $locale) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Variety', 'किस्म')->create();
    $seed = Seed::factory()->for($crop)->withTranslations('Seed', 'बीज')->create(['crop_variety_id' => $variety->id]);
    $season = Season::factory()->withNames('Winter', 'सर्दी')->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Reference']);
    $source->translations()->create(['locale' => 'hi', 'title' => 'स्रोत']);
    $rate = CropSeedRate::factory()->for($crop)->for($variety, 'cropVariety')->for($seed)->for($season)->create(['source_id' => $source->id]);
    $district = cropRateDistrict();
    $rate->regions()->attach($district);
    $image = SeedImage::factory()->for($seed)->create(['sort_order' => 1]);
    SeedImage::factory()->for($seed)->create(['sort_order' => 2]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)->getJson('/api/admin/crop-seed-rates/'.$rate->id)
        ->assertOk()->assertJsonPath('data.crop_name', $locale === 'hi' ? 'सरसों' : 'Mustard')->assertJsonPath('data.crop_variety_name', $locale === 'hi' ? 'किस्म' : 'Variety')
        ->assertJsonPath('data.season_name', $locale === 'hi' ? 'सर्दी' : 'Winter')->assertJsonPath('data.seed.name', $locale === 'hi' ? 'बीज' : 'Seed')
        ->assertJsonPath('data.source_title', $locale === 'hi' ? 'स्रोत' : 'Reference')->assertJsonPath('data.regions.0.name', 'Jaipur')
        ->assertJsonPath('data.seed.primary_image.id', $image->id)->assertJsonMissingPath('data.seed.images');
    expect($response->getContent())->not->toContain('attachments', 'review_notes', 'file_path');
})->with(['hi', 'en', 'gu']);

test('listing query count remains bounded as related record counts increase', function () {
    $admin = AdminUser::factory()->create();
    CropSeedRate::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/crop-seed-rates')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    CropSeedRate::factory()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/crop-seed-rates')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('mapping failures roll back recommendation fields and all pivot changes', function (bool $updating) {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = cropRateDistrict();
    $second = cropRateDistrict('Ajmer', $first->parent);
    $rate = $updating ? CropSeedRate::factory()->for($crop)->create(['region_scope' => 'specific']) : null;
    if ($rate !== null) {
        $rate->regions()->attach($first);
    }
    $payload = $updating ? ['notes' => 'Must rollback', 'region_ids' => [$second->id]] : cropRatePayload($crop, ['region_scope' => 'specific', 'region_ids' => [$second->id]]);
    DB::unprepared("CREATE TRIGGER fail_crop_rate_mapping BEFORE INSERT ON crop_seed_rate_regions BEGIN SELECT RAISE(ABORT, 'Simulated mapping failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->json($updating ? 'PATCH' : 'POST', '/api/admin/crop-seed-rates'.($rate === null ? '' : '/'.$rate->id), $payload)
            ->assertInternalServerError();
        $this->assertDatabaseCount('crop_seed_rates', $updating ? 1 : 0);
        if ($rate !== null) {
            expect($rate->fresh()->notes)->toBeNull();
            expect($rate->regions()->pluck('regions.id')->all())->toBe([$first->id]);
        } else {
            $this->assertDatabaseCount('crop_seed_rate_regions', 0);
        }
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_crop_rate_mapping');
    }
})->with(['create' => false, 'update' => true]);

test('referenced historical recommendations cannot be rewritten or deleted even through cascading references', function () {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create();
    Schema::create('crop_rate_history_probe', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('crop_seed_rate_id')->constrained('crop_seed_rates')->cascadeOnDelete();
    });
    DB::table('crop_rate_history_probe')->insert(['crop_seed_rate_id' => $rate->id]);
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/crop-seed-rates/'.$rate->id)->assertConflict();
        $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['rate_recommended' => '5'])->assertUnprocessable();
        $this->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', ['is_active' => false])->assertOk();
        expect($rate->fresh()->rate_recommended)->toBe('4.0000');
        $this->assertDatabaseCount('crop_rate_history_probe', 1);
    } finally {
        Schema::dropIfExists('crop_rate_history_probe');
    }
});

test('active deletion is blocked and inactive unreferenced deletion removes only its mappings', function () {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create(['region_scope' => 'specific']);
    $district = cropRateDistrict();
    $rate->regions()->attach($district);
    $active = CropSeedRate::factory()->create(['is_active' => true]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/crop-seed-rates/'.$active->id)->assertConflict();
    $this->deleteJson('/api/admin/crop-seed-rates/'.$rate->id)->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($rate);
    $this->assertModelExists($district);
    $this->assertDatabaseCount('crop_seed_rate_regions', 0);
});

test('database constraints reject invalid rate bounds and spacing outside HTTP writes', function (array $overrides) {
    $rate = CropSeedRate::factory()->create();
    try {
        DB::table('crop_seed_rates')->where('id', $rate->id)->update($overrides);
        $this->fail('The invalid recommendation was accepted.');
    } catch (QueryException) {
        expect($rate->fresh()->rate_recommended)->toBe('4.0000');
    }
})->with(['zero' => [['rate_recommended' => 0]], 'negative min' => [['rate_min' => -1]], 'minimum' => [['rate_min' => 5]],
    'maximum' => [['rate_max' => 3]], 'row spacing' => [['spacing_row_cm' => 0]], 'plant spacing' => [['spacing_plant_cm' => -1]]]);

test('inactive catalog selections cannot be newly attached to a recommendation', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $extra = match ($kind) {
        'variety' => ['crop_variety_id' => CropVariety::factory()->for($crop)->withNames()->create(['is_active' => false])->id],
        'season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => false])->id],
        default => ['seed_id' => Seed::factory()->for($crop)->completed()->create([
            'source_id' => $source->id, 'is_active' => $kind !== 'inactive seed',
            'verification_status' => $kind === 'unverified seed' ? 'pending' : 'verified',
            'setup_status' => $kind === 'draft seed' ? 'draft' : 'completed',
        ])->id],
    };

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-seed-rates', cropRatePayload($crop, ['source_id' => $source->id, ...$extra]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('crop_seed_rates', 0);
})->with(['variety' => ['variety', 'crop_variety_id'], 'season' => ['season', 'season_id'],
    'inactive seed' => ['inactive seed', 'seed_id'], 'draft seed' => ['draft seed', 'seed_id'], 'unverified seed' => ['unverified seed', 'seed_id']]);

test('partial crop changes reject newly incompatible retained varieties and seed products', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $other = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $seed = Seed::factory()->for($crop)->completed()->create(['crop_variety_id' => $variety->id]);
    $rate = CropSeedRate::factory()->for($crop)->for($variety, 'cropVariety')->for($seed)->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id, ['crop_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['crop_variety_id', 'seed_id']);
    expect($rate->fresh()->crop_id)->toBe($crop->id);
});

test('keyword search binds values and treats SQL wildcard characters literally', function (string $name, string $decoy, string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published($name, 'नमूना')->create();
    $other = Crop::factory()->published($decoy, 'अन्य')->create();
    $rate = CropSeedRate::factory()->for($crop)->create();
    CropSeedRate::factory()->for($other)->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-seed-rates?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $rate->id);
})->with(['percent' => ['100% crop', '1000 crop', '100%'], 'underscore' => ['crop_one', 'cropXone', 'crop_one'],
    'escape character' => ['crop!one', 'cropone', 'crop!one'], 'SQL input' => ["Crop ' OR 1=1 --", 'Other crop', "' OR 1=1 --"]]);

test('status requests reject invalid booleans and scientific-field updates with 422', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();
    $rate = CropSeedRate::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-seed-rates/'.$rate->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($rate->fresh()->is_active)->toBeFalse();
    expect($rate->fresh()->rate_recommended)->toBe('4.0000');
})->with(['missing' => [[], 'is_active'], 'invalid' => [['is_active' => 'yes'], 'is_active'],
    'scientific field' => [['is_active' => false, 'rate_recommended' => '9'], 'rate_recommended']]);

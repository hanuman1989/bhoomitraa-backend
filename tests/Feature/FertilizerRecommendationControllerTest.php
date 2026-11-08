<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\CropVariety;
use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
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

beforeEach(function () {
    $this->travelTo('2026-11-08 10:00:00');
});

/** @return array{nutrient_code: string, recommended_quantity: string, unit_code: string, area_unit_code: string} */
function recommendationNutrient(string $code = 'N', string $quantity = '80.0000'): array
{
    return ['nutrient_code' => $code, 'recommended_quantity' => $quantity, 'unit_code' => 'kg', 'area_unit_code' => 'ha'];
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function recommendationPayload(Crop $crop, array $overrides = []): array
{
    $sourceId = array_key_exists('source_id', $overrides) ? $overrides['source_id'] : KnowledgeSource::factory()->active()->create()->id;

    return array_replace(['crop_id' => $crop->id, 'recommendation_type' => 'general', 'region_scope' => 'all',
        'nutrients' => [recommendationNutrient()], 'source_id' => $sourceId, 'is_active' => false], $overrides);
}

function recommendationDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('general crop recommendations persist nutrient requirements and nullable optional fields', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $payload = recommendationPayload($crop);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', $payload)
        ->assertCreated()->assertJsonPath('data.crop_name', 'Mustard')->assertJsonPath('data.recommendation_type', 'general')
        ->assertJsonPath('data.nutrients.0.recommended_quantity', '80.0000')->assertJsonPath('data.nutrients.0.unit_code', 'kg')
        ->assertJsonPath('data.nutrients.0.area_unit_code', 'ha')->assertJsonPath('data.crop_variety', null)->assertJsonPath('data.season', null)
        ->assertJsonPath('data.reference_code', null)->assertJsonPath('data.reference_year', null)->assertJsonPath('data.soil_condition', null)
        ->assertJsonPath('data.region_ids', [])->assertJsonPath('data.is_active', false)->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $id = $response->json('data.id');
    expect($crop->fertilizerRecommendations()->sole()->id)->toBe($id);
    expect(KnowledgeSource::findOrFail($payload['source_id'])->fertilizerRecommendations()->sole()->id)->toBe($id);
    expect(FertilizerRecommendationNutrient::query()->sole()->fertilizerRecommendation->id)->toBe($id);
    $this->assertDatabaseHas('fertilizer_recommendations', ['id' => $id, 'source_id' => $payload['source_id']]);
    $this->assertDatabaseCount('fertilizer_recommendation_regions', 0);
});

test('variety season cultivation context and entered guidance are saved without deriving agricultural instructions', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    $payload = recommendationPayload($crop, ['crop_variety_id' => $variety->id, 'season_id' => $season->id, 'reference_year' => 2024,
        'reference_code' => 'TEST-001', 'irrigation_condition' => 'irrigated', 'soil_condition' => 'loamy', 'application_method' => 'soil_application',
        'basal_dose_notes' => 'Source-backed basal notes', 'top_dressing_notes' => 'Source-backed dressing notes', 'general_advisory' => 'Entered guidance',
        'notes' => 'Administrative note', 'nutrients' => [recommendationNutrient(), recommendationNutrient('P2O5', '40'), recommendationNutrient('K2O', '20')]]);
    $id = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', $payload)
        ->assertCreated()->assertJsonCount(3, 'data.nutrients')->assertJsonPath('data.basal_dose_notes', 'Source-backed basal notes')
        ->assertJsonPath('data.top_dressing_notes', 'Source-backed dressing notes')->assertJsonPath('data.general_advisory', 'Entered guidance')
        ->assertJsonPath('data.notes', 'Administrative note')->assertJsonPath('data.reference_year', 2024)->json('data.id');
    expect($variety->fertilizerRecommendations()->sole()->id)->toBe($id);
    expect($season->fertilizerRecommendations()->sole()->id)->toBe($id);
    $this->getJson('/api/admin/fertilizer-recommendations/'.$id)->assertOk()->assertJsonPath('data.reference_code', 'TEST-001')
        ->assertJsonPath('data.irrigation_condition', 'irrigated')->assertJsonPath('data.soil_condition', 'loamy')
        ->assertJsonPath('data.application_method', 'soil_application');
});

test('all recommendation endpoints require admin bearer authentication', function (string $method, string $suffix) {
    $record = FertilizerRecommendation::factory()->withNutrients()->create();
    $this->json($method, '/api/admin/fertilizer-recommendations'.str_replace('{id}', (string) $record->id, $suffix))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'options' => ['GET', '/options'], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('farmers inactive admins and tokens without admin abilities cannot access recommendations', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'ability']);

test('crop management authorization also protects the options endpoint', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations/options')->assertForbidden();
});

test('required recommendation fields return the project validation envelope', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['crop_id', 'recommendation_type', 'region_scope', 'nutrients', 'source_id']);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('fertilizer_recommendations', 0);
});

test('unsupported fields invalid foreign keys and malformed metadata return field errors without writes', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', recommendationPayload($crop, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('fertilizer_recommendations', 0);
    $this->assertDatabaseCount('fertilizer_recommendation_nutrients', 0);
})->with([
    'unknown crop' => ['crop_id', 999999], 'crop shape' => ['crop_id', []], 'unknown variety' => ['crop_variety_id', 999999],
    'unknown season' => ['season_id', 999999], 'unknown source' => ['source_id', 999999], 'null source' => ['source_id', null],
    'unapproved type' => ['recommendation_type', 'variety_specific'], 'unapproved irrigation type' => ['recommendation_type', 'irrigated'],
    'irrigation' => ['irrigation_condition', 'both'], 'mixed soil meaning' => ['soil_condition', 'alkaline'],
    'method' => ['application_method', 'basal_top_dressing'], 'code length' => ['reference_code', str_repeat('a', 101)],
    'year short' => ['reference_year', 999], 'year future' => ['reference_year', 2027], 'year overflow' => ['reference_year', 65536],
    'year float' => ['reference_year', '2024.5'], 'year padded' => ['reference_year', '02024'],
    'scope alias' => ['region_scope', 'specific_regions'], 'boolean' => ['is_active', 'yes'], 'notes length' => ['notes', str_repeat('a', 501)],
    'guidance shape' => ['general_advisory', []], 'guidance length' => ['basal_dose_notes', str_repeat('a', 65536)],
    'translations prohibited' => ['translations', ['en' => ['name' => 'Test']]], 'locale unnecessary' => ['locale', 'hi'],
    'commercial product prohibited' => ['fertilizer_id', 1], 'client timestamp' => ['created_at', '2026-11-08'],
]);

test('nutrient targets reject invalid rows and return nested field paths', function (array $rows, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', recommendationPayload($crop, ['nutrients' => $rows]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('fertilizer_recommendations', 0);
    $this->assertDatabaseCount('fertilizer_recommendation_nutrients', 0);
})->with([
    'empty' => [[], 'nutrients'], 'missing fields' => [[[]], 'nutrients.0.nutrient_code'],
    'duplicate' => [[recommendationNutrient(), recommendationNutrient()], 'nutrients.0.nutrient_code'],
    'unsupported code' => [[recommendationNutrient('Urea')], 'nutrients.0.nutrient_code'],
    'negative' => [[recommendationNutrient('N', '-1')], 'nutrients.0.recommended_quantity'],
    'zero' => [[recommendationNutrient('N', '0')], 'nutrients.0.recommended_quantity'],
    'precision' => [[recommendationNutrient('N', '1.12345')], 'nutrients.0.recommended_quantity'],
    'overflow' => [[recommendationNutrient('N', '10000000000')], 'nutrients.0.recommended_quantity'],
    'exponent' => [[recommendationNutrient('N', '8e1')], 'nutrients.0.recommended_quantity'],
    'invalid quantity' => [[recommendationNutrient('N', 'eighty')], 'nutrients.0.recommended_quantity'],
    'no conversions gram' => [[array_replace(recommendationNutrient(), ['unit_code' => 'g'])], 'nutrients.0.unit_code'],
    'volume' => [[array_replace(recommendationNutrient(), ['unit_code' => 'l'])], 'nutrients.0.unit_code'],
    'acre unsupported' => [[array_replace(recommendationNutrient(), ['area_unit_code' => 'acre'])], 'nutrients.0.area_unit_code'],
    'product prohibited' => [[array_replace(recommendationNutrient(), ['fertilizer_id' => 1])], 'nutrients.0'],
    'reparent prohibited' => [[array_replace(recommendationNutrient(), ['fertilizer_recommendation_id' => 1])], 'nutrients.0'],
    'object keys' => [['N' => recommendationNutrient()], 'nutrients'],
]);

test('options expose the shared vocabulary and every advertised option is accepted by writes', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $response = $this->getJson('/api/admin/fertilizer-recommendations/options')->assertOk()->assertJsonPath('pagination', null);
    $options = $response->json('data');
    expect(array_column($options['recommendation_types'], 'value'))->toBe(['general']);
    expect(array_column($options['irrigation_conditions'], 'value'))->toBe(CropSeedRate::IRRIGATION_CONDITIONS);
    expect(array_column($options['application_methods'], 'value'))->toBe(Fertilizer::APPLICATION_METHODS);
    expect(array_column($options['nutrient_codes'], 'value'))->toBe(FertilizerNutrient::CODES);
    expect(array_column($options['unit_codes'], 'value'))->toBe(['kg']);
    expect(array_column($options['area_unit_codes'], 'value'))->toBe(['ha']);
    expect(array_column($options['region_scopes'], 'value'))->toBe(['all', 'specific']);
    expect($options['soil_condition_definition'])->toContain('texture');
    foreach (['recommendation_types' => 'recommendation_type', 'irrigation_conditions' => 'irrigation_condition',
        'soil_conditions' => 'soil_condition', 'application_methods' => 'application_method'] as $group => $field) {
        foreach ($options[$group] as $option) {
            $this->postJson('/api/admin/fertilizer-recommendations', recommendationPayload($crop, [$field => $option['value']]))
                ->assertCreated()->assertJsonPath('data.'.$field, $option['value']);
        }
    }
    $rows = array_map(fn (array $option): array => recommendationNutrient($option['value'], '0.0001'), $options['nutrient_codes']);
    $this->postJson('/api/admin/fertilizer-recommendations', recommendationPayload($crop, ['nutrients' => $rows]))
        ->assertCreated()->assertJsonCount(12, 'data.nutrients')->assertJsonPath('data.nutrients.0.recommended_quantity', '0.0001');
});

test('reference code is nullable globally unique and excluded from its own update', function () {
    $admin = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->inactive()->withNutrients()->create(['reference_code' => 'REF-001']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($record->crop, ['reference_code' => 'REF-001']))->assertUnprocessable()->assertJsonValidationErrors('reference_code');
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['reference_code' => 'REF-001'])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['reference_code' => null])->assertOk()->assertJsonPath('data.reference_code', null);
});

test('cross crop and new inactive catalog choices are rejected', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $extra = match ($kind) {
        'other variety' => ['crop_variety_id' => CropVariety::factory()->withNames()->create(['is_active' => true])->id],
        'inactive variety' => ['crop_variety_id' => CropVariety::factory()->for($crop)->withNames()->create(['is_active' => false])->id],
        'inactive season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => false])->id], default => [],
    };
    if ($kind === 'inactive crop') {
        $crop->forceFill(['is_active' => false])->save();
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', recommendationPayload($crop, $extra))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['cross crop' => ['other variety', 'crop_variety_id'], 'variety' => ['inactive variety', 'crop_variety_id'],
    'season' => ['inactive season', 'season_id'], 'crop' => ['inactive crop', 'crop_id']]);

test('new sources must be active verified and have usable references', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $source = KnowledgeSource::factory()->active()->create(match ($kind) {
        'inactive' => ['is_active' => false], 'pending' => ['verification_status' => 'pending'],
        'unusable' => ['source_url' => null, 'reference_identifier' => null],
    });
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($crop, ['source_id' => $source->id]))->assertUnprocessable()->assertJsonValidationErrors('source_id');
})->with(['inactive', 'pending', 'unusable']);

test('nutrient synchronization preserves retained row ids removes omitted rows and preserves omitted collections', function () {
    $admin = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->inactive()->withNutrients()->create(['general_advisory' => 'Retain']);
    $nitrogenId = $record->nutrients()->sole()->id;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id,
        ['nutrients' => [recommendationNutrient('N', '90'), recommendationNutrient('S', '20')]])
        ->assertOk()->assertJsonPath('data.nutrients.0.id', $nitrogenId)->assertJsonPath('data.nutrients.0.recommended_quantity', '90.0000');
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['notes' => 'Updated'])->assertOk()->assertJsonCount(2, 'data.nutrients');
    $this->putJson('/api/admin/fertilizer-recommendations/'.$record->id, ['nutrients' => [recommendationNutrient('N', '100')]])
        ->assertOk()->assertJsonCount(1, 'data.nutrients')->assertJsonPath('data.general_advisory', 'Retain')->assertJsonPath('data.nutrients.0.id', $nitrogenId);
    $this->assertDatabaseCount('fertilizer_recommendations', 1);
    $this->assertDatabaseCount('fertilizer_recommendation_nutrients', 1);
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['nutrients' => []])->assertUnprocessable()->assertJsonValidationErrors('nutrients');
    expect($record->nutrients()->sole()->recommended_quantity)->toBe('100.0000');
});

test('region scope and district selections reject invalid mappings', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $district = recommendationDistrict();
    if ($kind === 'inactive') {
        $district->forceFill(['is_active' => false])->save();
    }
    $ids = match ($kind) {
        'empty' => [], 'state' => [$district->parent_id], 'unknown' => [999999], 'duplicate' => [$district->id, $district->id], default => [$district->id],
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($crop, ['region_scope' => $kind === 'all' ? 'all' : 'specific', 'region_ids' => $ids]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('fertilizer_recommendations', 0);
    $this->assertDatabaseCount('fertilizer_recommendation_regions', 0);
})->with(['empty' => ['empty', 'region_ids'], 'state' => ['state', 'region_ids.0'], 'unknown' => ['unknown', 'region_ids.0'],
    'duplicate' => ['duplicate', 'region_ids.0'], 'inactive' => ['inactive', 'region_ids.0'], 'all with maps' => ['all', 'region_ids']]);

test('district mappings save across states and retained inactive districts survive until explicitly removed', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = recommendationDistrict();
    $second = recommendationDistrict('Indore');
    $third = recommendationDistrict('Ajmer', $first->parent);
    $id = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($crop, ['region_scope' => 'specific', 'region_ids' => [$second->id, $first->id]]))->assertCreated()
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])->json('data.id');
    expect($first->fertilizerRecommendations()->sole()->id)->toBe($id);
    $first->forceFill(['is_active' => false])->save();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['notes' => 'Retain maps'])->assertOk()
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.is_active', false);
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_ids' => [$first->id, $third->id]])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_ids' => [$third->id]])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_ids' => [$first->id, $third->id]])->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_scope' => 'all'])->assertOk()->assertJsonPath('data.region_ids', []);
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_scope' => 'specific'])->assertUnprocessable()->assertJsonValidationErrors('region_ids');
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$id, ['region_scope' => 'specific', 'region_ids' => [$third->id]])->assertOk();
});

test('equal active contexts conflict regardless of nutrient quantity or reference code', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($record->crop, ['source_id' => $record->source_id, 'is_active' => true, 'reference_code' => 'Another-code',
            'nutrients' => [recommendationNutrient('N', '100')]]))->assertConflict();
    expect($response->json('message'))->toContain('recommendation '.$record->id.' ');
    $this->assertDatabaseCount('fertilizer_recommendations', 1);
    $this->assertDatabaseCount('fertilizer_recommendation_nutrients', 1);
});

test('independent sources years conditions and broader geographic contexts coexist', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create(['reference_year' => 2024]);
    $extra = match ($kind) {
        'year' => ['reference_year' => 2023], 'source' => ['source_id' => KnowledgeSource::factory()->active()->create()->id],
        'variety' => ['crop_variety_id' => CropVariety::factory()->for($record->crop)->withNames()->create(['is_active' => true])->id],
        'season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => true])->id],
        'irrigation' => ['irrigation_condition' => 'rainfed'], 'soil' => ['soil_condition' => 'clayey'],
        'method' => ['application_method' => 'fertigation'],
        'district' => ['region_scope' => 'specific', 'region_ids' => [recommendationDistrict()->id]],
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($record->crop, ['source_id' => $record->source_id, 'reference_year' => 2024, 'is_active' => true, ...$extra]))->assertCreated();
    $this->assertDatabaseCount('fertilizer_recommendations', 2);
})->with(['year', 'source', 'variety', 'season', 'irrigation', 'soil', 'method', 'district']);

test('equally specific contexts conflict only where selected districts intersect', function (bool $shared) {
    $admin = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create(['region_scope' => 'specific']);
    $first = recommendationDistrict();
    $second = recommendationDistrict('Ajmer', $first->parent);
    $record->regions()->attach($first);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations',
        recommendationPayload($record->crop, ['source_id' => $record->source_id, 'is_active' => true,
            'region_scope' => 'specific', 'region_ids' => [$shared ? $first->id : $second->id]]));
    $shared ? $response->assertConflict() : $response->assertCreated();
})->with(['intersecting' => true, 'disjoint' => false]);

test('activation rechecks conflicts and excludes the record itself during edits', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $first = FertilizerRecommendation::factory()->withNutrients()->create();
    $second = FertilizerRecommendation::factory()->inactive()->withNutrients()->for($first->crop)->create(['source_id' => $first->source_id]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$first->id,
        ['nutrients' => [recommendationNutrient('N', '85')]])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$second->id.'/status', ['is_active' => true])->assertConflict();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$first->id.'/status', ['is_active' => false])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$second->id.'/status', ['is_active' => true])->assertOk();
});

test('publication defaults follow benchmarks and require reviewer authorization', function () {
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $crop = Crop::factory()->published()->create();
    $payload = recommendationPayload($crop);
    unset($payload['is_active']);
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', $payload)->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizer-recommendations', $payload)->assertCreated()->assertJsonPath('data.is_active', true);
});

test('manager deactivation preserves targets guidance source year and district mappings', function () {
    $manager = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create(['region_scope' => 'specific', 'reference_year' => 2024,
        'basal_dose_notes' => 'Basal', 'top_dressing_notes' => 'Top', 'general_advisory' => 'Guidance']);
    $district = recommendationDistrict();
    $record->regions()->attach($district);
    $nutrientId = $record->nutrients()->sole()->id;
    $record->knowledgeSource->forceFill(['is_active' => false])->save();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['notes' => 'Unreviewed'])->assertForbidden();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => false])->assertOk()
        ->assertJsonPath('data.nutrients.0.id', $nutrientId)->assertJsonPath('data.nutrients.0.recommended_quantity', '80.0000')
        ->assertJsonPath('data.source_id', $record->source_id)->assertJsonPath('data.reference_year', 2024)
        ->assertJsonPath('data.basal_dose_notes', 'Basal')->assertJsonPath('data.top_dressing_notes', 'Top')
        ->assertJsonPath('data.general_advisory', 'Guidance')->assertJsonPath('data.region_ids', [$district->id]);
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => true])->assertForbidden();
});

test('status rejects invalid values and attempts to change scientific fields', function (array $payload, string $field) {
    $manager = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->inactive()->withNutrients()->create();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($record->fresh()->is_active)->toBeFalse();
    expect($record->nutrients()->sole()->recommended_quantity)->toBe('80.0000');
})->with(['missing' => [[], 'is_active'], 'boolean' => [['is_active' => 'yes'], 'is_active'],
    'targets' => [['is_active' => false, 'nutrients' => [recommendationNutrient()]], 'nutrients']]);

test('historical inactive parents remain editable while activation rechecks eligibility', function () {
    $reviewer = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->inactive()->withNutrients()->create();
    $record->crop->forceFill(['is_active' => false])->save();
    $record->knowledgeSource->forceFill(['is_active' => false])->save();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['notes' => 'Historical context'])->assertOk();
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors(['crop_id', 'source_id']);
});

test('activation rejects incomplete targets and invalid retained scientific metadata', function (string $field, mixed $value) {
    $reviewer = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->inactive()->create($field === 'nutrients' ? [] : [$field => $value]);
    if ($field !== 'nutrients') {
        FertilizerRecommendationNutrient::factory()->for($record)->create();
    }
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['no targets' => ['nutrients', null], 'year' => ['reference_year', 2027], 'type' => ['recommendation_type', 'legacy'],
    'scope' => ['region_scope', 'legacy'], 'soil' => ['soil_condition', 'alkaline']]);

test('safe deletion removes owned children and protects referenced history even with cascading foreign keys', function (bool $childReference) {
    $manager = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->inactive()->withNutrients()->create();
    Schema::create('test_fertilizer_history', function (Blueprint $table) use ($childReference): void {
        $table->id();
        $table->foreignId('reference_id')->constrained($childReference ? 'fertilizer_recommendation_nutrients' : 'fertilizer_recommendations')->cascadeOnDelete();
    });
    DB::table('test_fertilizer_history')->insert(['reference_id' => $childReference ? $record->nutrients()->sole()->id : $record->id]);
    try {
        $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/fertilizer-recommendations/'.$record->id)->assertConflict();
        $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['reference_year' => 2024])->assertUnprocessable();
        $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['nutrients' => [recommendationNutrient('N', '90')]])->assertConflict();
        $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['nutrients' => [recommendationNutrient(), recommendationNutrient('S', '20')]])->assertConflict();
        $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id, ['nutrients' => [recommendationNutrient()]])->assertOk();
        $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseCount('test_fertilizer_history', 1);
        expect($record->nutrients()->sole()->recommended_quantity)->toBe('80.0000');
    } finally {
        Schema::dropIfExists('test_fertilizer_history');
    }
    $district = recommendationDistrict();
    $record->regions()->attach($district);
    $this->deleteJson('/api/admin/fertilizer-recommendations/'.$record->id)->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($record);
    $this->assertModelExists($district);
    $this->assertDatabaseCount('fertilizer_recommendation_nutrients', 0);
    $this->assertDatabaseCount('fertilizer_recommendation_regions', 0);
})->with(['parent reference' => false, 'target reference' => true]);

test('activation revalidates stored nutrient codes and units omitted from status requests', function (string $field, string $value) {
    $admin = AdminUser::factory()->superUser()->create();
    $record = FertilizerRecommendation::factory()->inactive()->create();
    FertilizerRecommendationNutrient::factory()->for($record)->create([$field => $value]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('nutrients.0.'.$field);
    $this->patchJson('/api/admin/fertilizer-recommendations/'.$record->id.'/status', ['is_active' => false])->assertOk();
    expect($record->fresh()->is_active)->toBeFalse();
})->with(['nutrient' => ['nutrient_code', 'legacy'], 'unit' => ['unit_code', 'l'], 'area' => ['area_unit_code', 'acre']]);

test('active recommendations cannot be hard deleted', function () {
    $manager = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/fertilizer-recommendations/'.$record->id)->assertConflict();
    $this->assertModelExists($record);
});

test('nutrient and district write failures roll back masters children and previously saved mappings', function (bool $updating, string $failingTable) {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = recommendationDistrict();
    $second = recommendationDistrict('Ajmer', $first->parent);
    $record = $updating ? FertilizerRecommendation::factory()->inactive()->withNutrients()->for($crop)->create(['region_scope' => 'specific', 'notes' => 'Original']) : null;
    if ($record !== null) {
        $record->regions()->attach($first);
    }
    $input = ['region_ids' => [$second->id], 'notes' => 'Changed', 'nutrients' => [recommendationNutrient('S', '20')]];
    $payload = $updating ? $input : recommendationPayload($crop, ['region_scope' => 'specific', ...$input]);
    DB::unprepared('CREATE TRIGGER fail_fertilizer_rec_write BEFORE INSERT ON '.$failingTable." BEGIN SELECT RAISE(ABORT, 'Simulated failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->json($updating ? 'PATCH' : 'POST',
            '/api/admin/fertilizer-recommendations'.($record === null ? '' : '/'.$record->id), $payload)->assertInternalServerError();
        $this->assertDatabaseCount('fertilizer_recommendations', $updating ? 1 : 0);
        $this->assertDatabaseCount('fertilizer_recommendation_nutrients', $updating ? 1 : 0);
        if ($record !== null) {
            expect($record->fresh()->notes)->toBe('Original');
            expect($record->nutrients()->sole()->nutrient_code)->toBe('N');
            expect($record->regions()->pluck('regions.id')->all())->toBe([$first->id]);
        }
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_fertilizer_rec_write');
    }
})->with(['new target failure' => [false, 'fertilizer_recommendation_nutrients'], 'edited target failure' => [true, 'fertilizer_recommendation_nutrients'],
    'new district failure' => [false, 'fertilizer_recommendation_regions'], 'edited district failure' => [true, 'fertilizer_recommendation_regions']]);

test('database constraints prohibit nonpositive targets and duplicate nutrient codes outside HTTP', function (string $kind) {
    $record = FertilizerRecommendation::factory()->withNutrients()->create();
    try {
        if ($kind === 'duplicate') {
            $record->nutrients()->create(recommendationNutrient());
        } else {
            DB::table('fertilizer_recommendation_nutrients')->where('id', $record->nutrients()->sole()->id)->update(['recommended_quantity' => $kind === 'zero' ? 0 : -1]);
        }
        $this->fail('Invalid nutrient targets were accepted.');
    } catch (QueryException) {
        expect($record->nutrients()->sole()->recommended_quantity)->toBe('80.0000');
    }
})->with(['zero', 'negative', 'duplicate']);

test('partial crop changes reject retained varieties from the former crop', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $other = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $recommendation = FertilizerRecommendation::factory()->withNutrients()->inactive()->for($crop)->for($variety, 'cropVariety')->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizer-recommendations/'.$recommendation->id, ['crop_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    expect($recommendation->fresh()->crop_id)->toBe($crop->id);
});

test('listing searches translated crop variety and source titles', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Brijraj', 'ब्रिजराज')->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Research reference']);
    $source->translations()->create(['locale' => 'hi', 'title' => 'अनुसंधान स्रोत']);
    $recommendation = FertilizerRecommendation::factory()->withNutrients()->for($crop)->for($variety, 'cropVariety')->create(['source_id' => $source->id]);
    FertilizerRecommendation::factory()->withNutrients()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/fertilizer-recommendations?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $recommendation->id);
})->with(['Mustard', 'सरसों', 'Brijraj', 'ब्रिजराज', 'अनुसंधान स्रोत']);

test('each listing filter independently restricts results', function (string $field) {
    $admin = AdminUser::factory()->create();
    $recommendation = FertilizerRecommendation::factory()->withNutrients()->inactive()->create();
    $other = FertilizerRecommendation::factory()->withNutrients()->inactive()->for($recommendation->crop)->create(match ($field) {
        'crop_id' => ['crop_id' => Crop::factory()->published()->create()->id],
        'crop_variety_id' => ['crop_variety_id' => CropVariety::factory()->for($recommendation->crop)->create()->id],
        'season_id' => ['season_id' => Season::factory()->create()->id],
        'recommendation_type' => ['recommendation_type' => 'legacy_invalid'], 'region_scope' => ['region_scope' => 'specific'], 'is_active' => ['is_active' => true],
    });
    $target = in_array($field, ['crop_variety_id', 'season_id'], true) ? $other : $recommendation;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations?'.http_build_query([$field => $target->{$field}]))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $target->id);
})->with(['crop_id', 'crop_variety_id', 'season_id', 'recommendation_type', 'region_scope', 'is_active']);

test('listing validates crop variety consistency and pagination bounds', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations?crop_id='.$crop->id.'&crop_variety_id='.$variety->id)
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    $this->getJson('/api/admin/fertilizer-recommendations?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
});

test('pagination is stable preserves filters and omits district maps from list rows', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $recommendations = FertilizerRecommendation::factory()->withNutrients()->count(3)->for($crop)->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations?crop_id='.$crop->id.'&per_page=2')
        ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $recommendations[0]->id)
        ->assertJsonMissingPath('data.0.region_ids')->assertJsonMissingPath('data.0.regions');
    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id, 'per_page=2', 'page=2');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $recommendations[2]->id);
});

test('related names and source titles use header locale and English fallback without private source fields', function (string $locale) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Variety', 'किस्म')->create();
    $season = Season::factory()->withNames('Winter', 'सर्दी')->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Legacy title']);
    $source->translations()->createMany([['locale' => 'en', 'title' => 'Reference'], ['locale' => 'hi', 'title' => 'स्रोत']]);
    $recommendation = FertilizerRecommendation::factory()->withNutrients()->for($crop)->for($variety, 'cropVariety')->for($season)->create(['source_id' => $source->id, 'region_scope' => 'specific']);
    $district = recommendationDistrict();
    $recommendation->regions()->attach($district);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)->getJson('/api/admin/fertilizer-recommendations/'.$recommendation->id)
        ->assertOk()->assertJsonPath('data.crop_name', $locale === 'hi' ? 'सरसों' : 'Mustard')->assertJsonPath('data.crop_variety_name', $locale === 'hi' ? 'किस्म' : 'Variety')
        ->assertJsonPath('data.season_name', $locale === 'hi' ? 'सर्दी' : 'Winter')->assertJsonPath('data.source_title', $locale === 'hi' ? 'स्रोत' : 'Reference')
        ->assertJsonPath('data.regions.0.name', 'Jaipur');
    expect($response->getContent())->not->toContain('attachments', 'review_notes', 'file_path', 'seed_id');
    $keyword = $locale === 'hi' ? 'स्रोत' : 'Reference';
    $this->getJson('/api/admin/fertilizer-recommendations?keyword='.urlencode($keyword))->assertOk()->assertJsonPath('pagination.total', 1);
})->with(['hi', 'en', 'gu']);

test('keyword search binds values and treats SQL wildcard characters literally', function (string $name, string $decoy, string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published($name, 'नमूना')->create();
    $other = Crop::factory()->published($decoy, 'अन्य')->create();
    $recommendation = FertilizerRecommendation::factory()->withNutrients()->for($crop)->create();
    FertilizerRecommendation::factory()->withNutrients()->for($other)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $recommendation->id);
})->with(['percent' => ['100% crop', '1000 crop', '100%'], 'underscore' => ['crop_one', 'cropXone', 'crop_one'],
    'escape character' => ['crop!one', 'cropone', 'crop!one'], 'SQL input' => ["Crop ' OR 1=1 --", 'Other crop', "' OR 1=1 --"]]);

test('listing query counts remain bounded when unrelated recommendations increase', function () {
    $admin = AdminUser::factory()->create();
    FertilizerRecommendation::factory()->withNutrients()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/fertilizer-recommendations')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    FertilizerRecommendation::factory()->withNutrients()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/fertilizer-recommendations')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('keyword search includes reference codes and requested nutrients appear on list rows', function () {
    $admin = AdminUser::factory()->create();
    $record = FertilizerRecommendation::factory()->withNutrients()->create(['reference_code' => 'REF%_LITERAL']);
    FertilizerRecommendation::factory()->withNutrients()->create(['reference_code' => 'REFX_LITERAL']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizer-recommendations?keyword=REF%25_LITERAL')
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $record->id)->assertJsonPath('data.0.nutrients.0.recommended_quantity', '80.0000');
});

<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\CropYieldBenchmark;
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

/** @return array<string, mixed> */
function yieldBenchmarkPayload(Crop $crop, array $overrides = []): array
{
    $sourceId = array_key_exists('source_id', $overrides) ? $overrides['source_id'] : KnowledgeSource::factory()->active()->create()->id;

    return array_replace(['crop_id' => $crop->id, 'region_scope' => 'all', 'yield_avg_kg_ha' => '2000.000',
        'benchmark_type' => 'research_trial', 'source_id' => $sourceId, 'is_active' => false], $overrides);
}

function yieldBenchmarkDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('crop level benchmarks save optional values as null with decimal strings and the standard envelope', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $payload = yieldBenchmarkPayload($crop);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', $payload)
        ->assertCreated()->assertJsonPath('data.crop_name', 'Mustard')->assertJsonPath('data.yield_avg_kg_ha', '2000.000')
        ->assertJsonPath('data.yield_min_kg_ha', null)->assertJsonPath('data.yield_max_kg_ha', null)->assertJsonPath('data.reference_year', null)
        ->assertJsonPath('data.crop_variety', null)->assertJsonPath('data.season', null)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.region_ids', [])->assertJsonPath('data.regions', [])->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('crop_yield_benchmarks', ['id' => $response->json('data.id'), 'source_id' => $payload['source_id']]);
    expect($crop->yieldBenchmarks()->sole()->id)->toBe($response->json('data.id'));
    expect(KnowledgeSource::findOrFail($payload['source_id'])->yieldBenchmarks()->sole()->id)->toBe($response->json('data.id'));
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
});

test('approved active default is applied only through reviewer authorized publication', function () {
    $crop = Crop::factory()->published()->create();
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $payload = yieldBenchmarkPayload($crop);
    unset($payload['is_active']);
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', $payload)->assertForbidden();
    $this->assertDatabaseCount('crop_yield_benchmarks', 0);
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', $payload)
        ->assertCreated()->assertJsonPath('data.is_active', true);
    expect(CropYieldBenchmark::query()->sole()->is_active)->toBeTrue();
});

test('variety and season benchmarks retain valid relationships and independent optional yield bounds', function (array $bounds) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    $id = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($crop, ['crop_variety_id' => $variety->id, 'season_id' => $season->id, 'reference_year' => 2024, ...$bounds]))
        ->assertCreated()->assertJsonPath('data.crop_variety_id', $variety->id)->assertJsonPath('data.season_id', $season->id)
        ->assertJsonPath('data.reference_year', 2024)->json('data.id');
    expect($variety->yieldBenchmarks()->sole()->id)->toBe($id);
    expect($season->yieldBenchmarks()->sole()->id)->toBe($id);
})->with(['minimum only' => [['yield_min_kg_ha' => '0']], 'maximum only' => [['yield_max_kg_ha' => '2400']],
    'equal bounds' => [['yield_min_kg_ha' => '2000', 'yield_max_kg_ha' => '2000']]]);

test('benchmark endpoints reject missing authentication with 401', function (string $method, string $suffix) {
    $benchmark = CropYieldBenchmark::factory()->create();
    $this->json($method, '/api/admin/crop-yield-benchmarks'.str_replace('{id}', (string) $benchmark->id, $suffix))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'], 'update' => ['PATCH', '/{id}'],
    'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('farmer identities inactive admins and missing abilities cannot manage benchmarks', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'ability']);

test('crop management gate protects all benchmark operations', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks')->assertForbidden();
});

test('required benchmark fields cannot be omitted even though average storage is nullable', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['crop_id', 'region_scope', 'yield_avg_kg_ha', 'benchmark_type', 'source_id']);
    $this->assertDatabaseCount('crop_yield_benchmarks', 0);
});

test('invalid primitive values and unapproved screenshot fields return 422 without persistence', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', yieldBenchmarkPayload($crop, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($response->json('errors.'.$field.'.0'))->toBeString()->not->toBe('');
    $this->assertDatabaseCount('crop_yield_benchmarks', 0);
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
})->with([
    'unknown crop' => ['crop_id', 999999], 'crop array' => ['crop_id', []], 'unknown variety' => ['crop_variety_id', 999999], 'unknown season' => ['season_id', 999999],
    'average zero' => ['yield_avg_kg_ha', 0], 'average negative' => ['yield_avg_kg_ha', -1], 'average null' => ['yield_avg_kg_ha', null],
    'average precision' => ['yield_avg_kg_ha', '2000.1234'], 'average overflow' => ['yield_avg_kg_ha', '1000000000'],
    'scientific notation' => ['yield_avg_kg_ha', '2e3'], 'formatted number' => ['yield_avg_kg_ha', '2,000'],
    'minimum negative' => ['yield_min_kg_ha', -1], 'minimum above' => ['yield_min_kg_ha', '2000.001'], 'minimum precision' => ['yield_min_kg_ha', '1.1234'],
    'maximum below' => ['yield_max_kg_ha', '1999.999'], 'maximum negative' => ['yield_max_kg_ha', -1], 'maximum precision' => ['yield_max_kg_ha', '2500.1234'],
    'maximum overflow' => ['yield_max_kg_ha', '1000000000'], 'type' => ['benchmark_type', 'invalid'],
    'unknown source' => ['source_id', 999999], 'missing source' => ['source_id', null],
    'year too short' => ['reference_year', 999], 'year future' => ['reference_year', 2027], 'year unsigned overflow' => ['reference_year', 65536],
    'year float' => ['reference_year', '2024.5'], 'year too long' => ['reference_year', '02024'], 'year nonnumeric' => ['reference_year', 'current'],
    'scope alias' => ['region_scope', 'specific_regions'], 'boolean' => ['is_active', 'yes'], 'notes length' => ['notes', str_repeat('a', 65536)],
    'notes type' => ['notes', []], 'seed not approved' => ['seed_id', 1], 'material not approved' => ['material_type', 'seed'],
    'unit not approved' => ['yield_unit', 'kg/ha'], 'irrigation not approved' => ['irrigation_condition', 'irrigated'], 'soil not approved' => ['soil_type', 'clay'],
    'district field not approved' => ['district_id', 1], 'profit not approved' => ['expected_profit', 2000], 'revenue not approved' => ['estimated_revenue', 5000],
    'verification not approved' => ['verification_status', 'verified'], 'translation not approved' => ['translations', ['en' => ['name' => 'Unknown']]],
    'client timestamp' => ['created_at', '2026-11-08'], 'locale request' => ['locale', 'hi'],
]);

test('all five approved benchmark types persist unchanged', function (string $type) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $id = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($crop, ['benchmark_type' => $type, 'reference_year' => 2026]))->assertCreated()->assertJsonPath('data.benchmark_type', $type)->json('data.id');
    $this->assertDatabaseHas('crop_yield_benchmarks', ['id' => $id, 'benchmark_type' => $type, 'reference_year' => 2026]);
})->with(['research_trial', 'demonstration', 'district_average', 'state_average', 'variety_potential']);

test('cross crop and new inactive catalog relationships are rejected', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $extra = match ($kind) {
        'other crop variety' => ['crop_variety_id' => CropVariety::factory()->withNames()->create(['is_active' => true])->id],
        'inactive variety' => ['crop_variety_id' => CropVariety::factory()->for($crop)->withNames()->create(['is_active' => false])->id],
        'inactive season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => false])->id],
        default => [],
    };
    if ($kind === 'inactive crop') {
        $crop->forceFill(['is_active' => false])->save();
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks', yieldBenchmarkPayload($crop, $extra))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['cross crop' => ['other crop variety', 'crop_variety_id'], 'variety' => ['inactive variety', 'crop_variety_id'],
    'season' => ['inactive season', 'season_id'], 'crop' => ['inactive crop', 'crop_id']]);

test('scientific source selections must be active verified and usable', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $source = KnowledgeSource::factory()->active()->create(match ($kind) {
        'inactive' => ['is_active' => false], 'pending' => ['verification_status' => 'pending'],
        'no reference' => ['source_url' => null, 'reference_identifier' => null],
    });
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($crop, ['source_id' => $source->id]))->assertUnprocessable()->assertJsonValidationErrors('source_id');
})->with(['inactive', 'pending', 'no reference']);

test('specific benchmarks save districts across states and expose region preselection fields', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = yieldBenchmarkDistrict();
    $second = yieldBenchmarkDistrict('Indore');
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($crop, ['region_scope' => 'specific', 'region_ids' => [$second->id, $first->id]]))->assertCreated()
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.name', 'Jaipur')
        ->assertJsonPath('data.regions.0.parent_id', $first->parent_id)->assertJsonPath('data.regions.0.is_active', true);
    expect($first->yieldBenchmarks()->sole()->id)->toBe($response->json('data.id'));
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 2);
});

test('invalid empty duplicate state or new inactive districts return field errors', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $district = yieldBenchmarkDistrict();
    if ($kind === 'inactive') {
        $district->forceFill(['is_active' => false])->save();
    }
    $ids = match ($kind) {
        'empty' => [], 'state' => [$district->parent_id], 'unknown' => [999999], 'duplicate' => [$district->id, $district->id], default => [$district->id]
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($crop, ['region_scope' => $kind === 'all' ? 'all' : 'specific', 'region_ids' => $ids]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('crop_yield_benchmarks', 0);
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
})->with(['empty' => ['empty', 'region_ids'], 'state' => ['state', 'region_ids.0'], 'unknown' => ['unknown', 'region_ids.0'],
    'duplicate' => ['duplicate', 'region_ids.0'], 'inactive' => ['inactive', 'region_ids.0'], 'all with maps' => ['all', 'region_ids']]);

test('partial edits and scope transitions retain inactive saved districts until explicitly removed', function () {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create(['region_scope' => 'specific']);
    $first = yieldBenchmarkDistrict();
    $second = yieldBenchmarkDistrict('Ajmer', $first->parent);
    $third = yieldBenchmarkDistrict('Alwar', $first->parent);
    $benchmark->regions()->attach([$first->id, $second->id]);
    $first->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['notes' => 'Partial edit'])
        ->assertOk()->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.is_active', false);
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_ids' => [$first->id, $third->id]])->assertOk();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_ids' => [$third->id]])->assertOk();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_ids' => [$first->id, $third->id]])->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_scope' => 'all'])->assertOk()->assertJsonPath('data.region_ids', []);
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_scope' => 'specific'])->assertUnprocessable()->assertJsonValidationErrors('region_ids');
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_scope' => 'specific', 'region_ids' => [$third->id]])->assertOk()->assertJsonPath('data.region_ids', [$third->id]);
    expect($benchmark->fresh()->notes)->toBe('Partial edit');
});

test('partial average changes revalidate each retained yield boundary', function (string $field, string $value) {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create(['yield_min_kg_ha' => '1600', 'yield_max_kg_ha' => '2400']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['yield_avg_kg_ha' => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($benchmark->fresh()->yield_avg_kg_ha)->toBe('2000.000');
})->with(['minimum' => ['yield_min_kg_ha', '1599.999'], 'maximum' => ['yield_max_kg_ha', '2400.001']]);

test('exact active scientific identities conflict regardless of changed yield numbers', function (string $average) {
    $admin = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->create(['reference_year' => 2024]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($benchmark->crop, ['is_active' => true, 'yield_avg_kg_ha' => $average, 'source_id' => $benchmark->source_id, 'reference_year' => 2024]))->assertConflict();
    expect($response->json('message'))->toContain('benchmark '.$benchmark->id.' ');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('crop_yield_benchmarks', 1);
})->with(['same value' => '2000.000', 'changed value' => '2200.000']);

test('different evidence and broader or narrower references coexist legitimately', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->create(['reference_year' => 2024]);
    $extra = match ($kind) {
        'type' => ['benchmark_type' => 'district_average'], 'year' => ['reference_year' => 2023],
        'source' => ['source_id' => KnowledgeSource::factory()->active()->create()->id],
        'variety' => ['crop_variety_id' => CropVariety::factory()->for($benchmark->crop)->withNames()->create(['is_active' => true])->id],
        'season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => true])->id],
        'district' => ['region_scope' => 'specific', 'region_ids' => [yieldBenchmarkDistrict()->id]],
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($benchmark->crop, ['is_active' => true, 'source_id' => $benchmark->source_id, 'reference_year' => 2024, ...$extra]))->assertCreated();
    $this->assertDatabaseCount('crop_yield_benchmarks', 2);
})->with(['type', 'year', 'source', 'variety', 'season', 'district']);

test('equally specific district benchmarks conflict only when selected districts intersect', function (bool $shared) {
    $admin = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->create(['region_scope' => 'specific']);
    $first = yieldBenchmarkDistrict();
    $second = yieldBenchmarkDistrict('Ajmer', $first->parent);
    $benchmark->regions()->attach($first);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/crop-yield-benchmarks',
        yieldBenchmarkPayload($benchmark->crop, ['is_active' => true, 'source_id' => $benchmark->source_id, 'region_scope' => 'specific', 'region_ids' => [$shared ? $first->id : $second->id]]));
    $shared ? $response->assertConflict() : $response->assertCreated();
})->with(['shared' => true, 'disjoint' => false]);

test('active edits exclude the current record but cannot change identity into another active benchmark', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $first = CropYieldBenchmark::factory()->create(['reference_year' => 2024]);
    $second = CropYieldBenchmark::factory()->for($first->crop)->create(['source_id' => $first->source_id, 'reference_year' => 2023]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$first->id, ['yield_avg_kg_ha' => '2100.125'])
        ->assertOk()->assertJsonPath('data.yield_avg_kg_ha', '2100.125')->assertJsonPath('data.id', $first->id);
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$second->id, ['reference_year' => 2024])->assertConflict();
    expect($second->fresh()->reference_year)->toBe(2023);
});

test('inactive overlaps are allowed and activation rechecks scientific identity without losing maps', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $first = CropYieldBenchmark::factory()->create(['region_scope' => 'specific']);
    $second = CropYieldBenchmark::factory()->inactive()->for($first->crop)->create(['region_scope' => 'specific', 'source_id' => $first->source_id]);
    $district = yieldBenchmarkDistrict();
    $first->regions()->attach($district);
    $second->regions()->attach($district);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$second->id.'/status', ['is_active' => true])->assertConflict();
    expect($second->fresh()->is_active)->toBeFalse();
    expect($second->regions()->pluck('regions.id')->all())->toBe([$district->id]);
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$first->id.'/status', ['is_active' => false])->assertOk();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$second->id.'/status', ['is_active' => true])->assertOk();
});

test('publication and active edits require a reviewer while deactivation preserves all scientific fields', function () {
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create(['region_scope' => 'specific', 'yield_min_kg_ha' => '1600',
        'yield_max_kg_ha' => '2400', 'reference_year' => 2024, 'notes' => 'Scientific context']);
    $district = yieldBenchmarkDistrict();
    $benchmark->regions()->attach($district);
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])->assertOk();
    $district->forceFill(['is_active' => false])->save();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['region_ids' => [$district->id]])->assertOk()->assertJsonPath('data.regions.0.is_active', false);
    app('auth')->forgetGuards();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['notes' => 'Unreviewed'])->assertForbidden();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => false])->assertOk()
        ->assertJsonPath('data.yield_min_kg_ha', '1600.000')->assertJsonPath('data.yield_avg_kg_ha', '2000.000')->assertJsonPath('data.yield_max_kg_ha', '2400.000')
        ->assertJsonPath('data.reference_year', 2024)->assertJsonPath('data.source_id', $benchmark->source_id)
        ->assertJsonPath('data.notes', 'Scientific context')->assertJsonPath('data.region_ids', [$district->id]);
});

test('historical missing averages remain nullable and readable but cannot be published without completion', function () {
    $reviewer = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create(['yield_avg_kg_ha' => null]);
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id)->assertOk()->assertJsonPath('data.yield_avg_kg_ha', null);
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('yield_avg_kg_ha');
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['yield_avg_kg_ha' => '1800.123'])->assertOk();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.yield_avg_kg_ha', '1800.123');
});

test('historical inactive parents remain editable but cannot be newly selected or republished', function () {
    $reviewer = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create();
    $benchmark->crop->forceFill(['is_active' => false])->save();
    $benchmark->knowledgeSource->forceFill(['is_active' => false])->save();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['notes' => 'Historical context'])->assertOk();
    $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors(['crop_id', 'source_id']);
    $this->postJson('/api/admin/crop-yield-benchmarks', yieldBenchmarkPayload($benchmark->crop))->assertUnprocessable()->assertJsonValidationErrors('crop_id');
});

test('partial crop changes reject retained varieties from the former crop', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $other = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $benchmark = CropYieldBenchmark::factory()->inactive()->for($crop)->for($variety, 'cropVariety')->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['crop_id' => $other->id])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    expect($benchmark->fresh()->crop_id)->toBe($crop->id);
});

test('listing searches translated crop variety and source titles', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Brijraj', 'ब्रिजराज')->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Research reference']);
    $source->translations()->create(['locale' => 'hi', 'title' => 'अनुसंधान स्रोत']);
    $benchmark = CropYieldBenchmark::factory()->for($crop)->for($variety, 'cropVariety')->create(['source_id' => $source->id]);
    CropYieldBenchmark::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-yield-benchmarks?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $benchmark->id);
})->with(['Mustard', 'सरसों', 'Brijraj', 'ब्रिजराज', 'अनुसंधान स्रोत']);

test('each listing filter independently restricts results', function (string $field) {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create();
    $other = CropYieldBenchmark::factory()->inactive()->for($benchmark->crop)->create(match ($field) {
        'crop_id' => ['crop_id' => Crop::factory()->published()->create()->id],
        'crop_variety_id' => ['crop_variety_id' => CropVariety::factory()->for($benchmark->crop)->create()->id],
        'season_id' => ['season_id' => Season::factory()->create()->id],
        'benchmark_type' => ['benchmark_type' => 'district_average'], 'region_scope' => ['region_scope' => 'specific'], 'is_active' => ['is_active' => true],
    });
    $target = in_array($field, ['crop_variety_id', 'season_id'], true) ? $other : $benchmark;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks?'.http_build_query([$field => $target->{$field}]))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $target->id);
})->with(['crop_id', 'crop_variety_id', 'season_id', 'benchmark_type', 'region_scope', 'is_active']);

test('listing validates crop variety consistency and pagination bounds', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks?crop_id='.$crop->id.'&crop_variety_id='.$variety->id)
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    $this->getJson('/api/admin/crop-yield-benchmarks?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
});

test('pagination is stable preserves filters and omits district maps from list rows', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $benchmarks = CropYieldBenchmark::factory()->count(3)->for($crop)->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks?crop_id='.$crop->id.'&per_page=2')
        ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $benchmarks[0]->id)
        ->assertJsonMissingPath('data.0.region_ids')->assertJsonMissingPath('data.0.regions');
    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id, 'per_page=2', 'page=2');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $benchmarks[2]->id);
});

test('related names and source titles use header locale and English fallback without private source fields', function (string $locale) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published('Mustard', 'सरसों')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Variety', 'किस्म')->create();
    $season = Season::factory()->withNames('Winter', 'सर्दी')->create();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Legacy title']);
    $source->translations()->createMany([['locale' => 'en', 'title' => 'Reference'], ['locale' => 'hi', 'title' => 'स्रोत']]);
    $benchmark = CropYieldBenchmark::factory()->for($crop)->for($variety, 'cropVariety')->for($season)->create(['source_id' => $source->id, 'region_scope' => 'specific']);
    $district = yieldBenchmarkDistrict();
    $benchmark->regions()->attach($district);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)->getJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id)
        ->assertOk()->assertJsonPath('data.crop_name', $locale === 'hi' ? 'सरसों' : 'Mustard')->assertJsonPath('data.crop_variety_name', $locale === 'hi' ? 'किस्म' : 'Variety')
        ->assertJsonPath('data.season_name', $locale === 'hi' ? 'सर्दी' : 'Winter')->assertJsonPath('data.source_title', $locale === 'hi' ? 'स्रोत' : 'Reference')
        ->assertJsonPath('data.regions.0.name', 'Jaipur');
    expect($response->getContent())->not->toContain('attachments', 'review_notes', 'file_path', 'seed_id');
    $keyword = $locale === 'hi' ? 'स्रोत' : 'Reference';
    $this->getJson('/api/admin/crop-yield-benchmarks?keyword='.urlencode($keyword))->assertOk()->assertJsonPath('pagination.total', 1);
})->with(['hi', 'en', 'gu']);

test('keyword search binds values and treats SQL wildcard characters literally', function (string $name, string $decoy, string $keyword) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published($name, 'नमूना')->create();
    $other = Crop::factory()->published($decoy, 'अन्य')->create();
    $benchmark = CropYieldBenchmark::factory()->for($crop)->create();
    CropYieldBenchmark::factory()->for($other)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-yield-benchmarks?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $benchmark->id);
})->with(['percent' => ['100% crop', '1000 crop', '100%'], 'underscore' => ['crop_one', 'cropXone', 'crop_one'],
    'escape character' => ['crop!one', 'cropone', 'crop!one'], 'SQL input' => ["Crop ' OR 1=1 --", 'Other crop', "' OR 1=1 --"]]);

test('listing query counts remain bounded when unrelated benchmarks increase', function () {
    $admin = AdminUser::factory()->create();
    CropYieldBenchmark::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/crop-yield-benchmarks')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    CropYieldBenchmark::factory()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/crop-yield-benchmarks')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('failed district writes roll back both new benchmarks and edited values with detached mappings', function (bool $updating) {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $first = yieldBenchmarkDistrict();
    $second = yieldBenchmarkDistrict('Ajmer', $first->parent);
    $benchmark = $updating ? CropYieldBenchmark::factory()->inactive()->for($crop)->create(['region_scope' => 'specific']) : null;
    if ($benchmark !== null) {
        $benchmark->regions()->attach($first);
    }
    $payload = $updating ? ['yield_avg_kg_ha' => '2100', 'region_ids' => [$second->id]] : yieldBenchmarkPayload($crop, ['region_scope' => 'specific', 'region_ids' => [$second->id]]);
    DB::unprepared("CREATE TRIGGER fail_yield_mapping BEFORE INSERT ON crop_yield_benchmark_regions BEGIN SELECT RAISE(ABORT, 'Simulated mapping failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->json($updating ? 'PATCH' : 'POST', '/api/admin/crop-yield-benchmarks'.($benchmark === null ? '' : '/'.$benchmark->id), $payload)
            ->assertInternalServerError();
        $this->assertDatabaseCount('crop_yield_benchmarks', $updating ? 1 : 0);
        if ($benchmark !== null) {
            expect($benchmark->fresh()->yield_avg_kg_ha)->toBe('2000.000');
            expect($benchmark->regions()->pluck('regions.id')->all())->toBe([$first->id]);
        } else {
            $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
        }
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_yield_mapping');
    }
})->with(['create' => false, 'update' => true]);

test('referenced farmer yield estimates block benchmark deletion and scientific rewrites even with cascade foreign keys', function () {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create();
    Schema::create('farm_crop_yield_estimates', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('yield_benchmark_id')->constrained('crop_yield_benchmarks')->cascadeOnDelete();
    });
    DB::table('farm_crop_yield_estimates')->insert(['yield_benchmark_id' => $benchmark->id]);
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id)->assertConflict();
        $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id, ['yield_avg_kg_ha' => '2100'])->assertUnprocessable();
        $this->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => false])->assertOk();
        expect($benchmark->fresh()->yield_avg_kg_ha)->toBe('2000.000');
        $this->assertDatabaseCount('farm_crop_yield_estimates', 1);
    } finally {
        Schema::dropIfExists('farm_crop_yield_estimates');
    }
});

test('active benchmarks cannot be deleted and safe inactive deletion removes only owned maps', function () {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create(['region_scope' => 'specific']);
    $district = yieldBenchmarkDistrict();
    $benchmark->regions()->attach($district);
    $active = CropYieldBenchmark::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/crop-yield-benchmarks/'.$active->id)->assertConflict();
    $this->deleteJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id)->assertOk()->assertJsonPath('data', null);
    $this->assertModelMissing($benchmark);
    $this->assertModelExists($district);
    $this->assertDatabaseCount('crop_yield_benchmark_regions', 0);
});

test('status requests reject invalid booleans and scientific field updates', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($benchmark->fresh()->is_active)->toBeFalse();
    expect($benchmark->fresh()->yield_avg_kg_ha)->toBe('2000.000');
})->with(['missing' => [[], 'is_active'], 'invalid' => [['is_active' => 'yes'], 'is_active'],
    'scientific field' => [['is_active' => false, 'yield_avg_kg_ha' => '2100'], 'yield_avg_kg_ha']]);

test('database constraints reject invalid nonnull yields and years outside HTTP writes', function (array $overrides) {
    $benchmark = CropYieldBenchmark::factory()->create();
    try {
        DB::table('crop_yield_benchmarks')->where('id', $benchmark->id)->update($overrides);
        $this->fail('Invalid benchmark values were accepted.');
    } catch (QueryException) {
        expect($benchmark->fresh()->yield_avg_kg_ha)->toBe('2000.000');
    }
})->with(['average' => [['yield_avg_kg_ha' => 0]], 'minimum negative' => [['yield_min_kg_ha' => -1]],
    'minimum above' => [['yield_min_kg_ha' => 2001]], 'maximum below' => [['yield_max_kg_ha' => 1999]],
    'maximum negative' => [['yield_max_kg_ha' => -1]], 'year' => [['reference_year' => 999]]]);

test('activation validates stored scientific metadata omitted from status requests', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->superUser()->create();
    $benchmark = CropYieldBenchmark::factory()->inactive()->create([$field => $value]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/crop-yield-benchmarks/'.$benchmark->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($benchmark->fresh()->is_active)->toBeFalse();
})->with(['future year' => ['reference_year', 2027], 'unsupported type' => ['benchmark_type', 'legacy_invalid'],
    'unsupported scope' => ['region_scope', 'legacy_invalid']]);

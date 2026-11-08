<?php

use App\Models\AdminUser;
use App\Models\Fertilizer;
use App\Models\FertilizerImage;
use App\Models\FertilizerNutrient;
use App\Models\KnowledgeSource;
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
function fertilizerBasicPayload(array $overrides = []): array
{
    return array_replace(['name' => 'Urea', 'slug' => 'urea', 'fertilizer_type' => 'straight', 'form' => 'granular', 'default_unit_code' => 'kg'], $overrides);
}

/** @return array{nutrient_code: string, content_value: string, content_unit_code: string} */
function fertilizerNutrientRow(array $overrides = []): array
{
    return array_replace(['nutrient_code' => 'N', 'content_value' => '46.0000', 'content_unit_code' => 'percent_ww'], $overrides);
}

function fertilizerReady(array $overrides = []): Fertilizer
{
    $fertilizer = Fertilizer::factory()->withTranslations('Urea', 'यूरिया')->create(['completed_step' => 4, ...$overrides]);
    FertilizerNutrient::factory()->for($fertilizer)->create();

    return $fertilizer;
}

test('step one creates one inactive pending draft with primary name stored only in its translation', function () {
    $admin = AdminUser::factory()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/step-1',
        fertilizerBasicPayload(['fertilizer_code' => 'FRT-001', 'grade' => '46-0-0', 'reference_pack_quantity' => '50', 'reference_pack_unit_code' => 'kg']))
        ->assertCreated()->assertJsonPath('data.name', 'Urea')->assertJsonPath('data.translations.en.name', 'Urea')
        ->assertJsonPath('data.reference_pack_quantity', '50.0000')->assertJsonPath('data.verification_status', 'pending')
        ->assertJsonPath('data.setup_status', 'draft')->assertJsonPath('data.completed_step', 1)->assertJsonPath('data.next_step', 2)
        ->assertJsonPath('data.is_active', false)->assertJsonCount(0, 'data.nutrients')->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect($response->json('data.fertilizer_id'))->toBe($response->json('data.id'));
    $this->assertDatabaseCount('fertilizers', 1);
    $this->assertDatabaseCount('fertilizer_translations', 1);
    $this->assertDatabaseCount('fertilizer_nutrients', 0);
    expect(Fertilizer::published()->count())->toBe(0);
    expect(Schema::hasColumn('fertilizers', 'name'))->toBeFalse();
});

test('each fertilizer endpoint rejects missing authentication', function (string $method, string $suffix) {
    $fertilizer = Fertilizer::factory()->create();
    $this->json($method, '/api/admin/fertilizers'.str_replace('{id}', (string) $fertilizer->id, $suffix))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'create' => ['POST', '/step-1'], 'show' => ['GET', '/{id}'], 'basic' => ['PATCH', '/{id}/step-1'],
    'nutrients' => ['PUT', '/{id}/step-2'], 'translations' => ['PUT', '/{id}/step-3'], 'media' => ['POST', '/{id}/step-4'],
    'review' => ['GET', '/{id}/review'], 'finalize' => ['POST', '/{id}/finalize'], 'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('farmer accounts inactive admins and missing admin abilities cannot manage fertilizers', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/fertilizers');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'ability']);

test('fertilizer management gate is enforced separately from authentication', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-fertilizers', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizers')->assertForbidden();
});

test('step one requires primary name slug classification form and default unit', function () {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/step-1', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'slug', 'fertilizer_type', 'form', 'default_unit_code']);
    $this->assertDatabaseCount('fertilizers', 0);
});

test('invalid basic values and server-managed fields return 422 without draft creation', function (string $field, mixed $value, ?string $errorField = null) {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($errorField ?? $field);
    $this->assertDatabaseCount('fertilizers', 0);
    $this->assertDatabaseCount('fertilizer_translations', 0);
})->with([
    'name length' => ['name', str_repeat('a', 201)], 'slug format' => ['slug', 'Not URL Friendly'], 'slug path' => ['slug', '../urea'],
    'code length' => ['fertilizer_code', str_repeat('a', 101)], 'type' => ['fertilizer_type', 'unsupported'], 'form' => ['form', 'unsupported'],
    'brand' => ['brand_name', str_repeat('a', 151)], 'manufacturer' => ['manufacturer_name', str_repeat('a', 201)], 'grade' => ['grade', str_repeat('a', 51)],
    'unit' => ['default_unit_code', 'plant'], 'pack zero' => ['reference_pack_quantity', 0], 'pack negative' => ['reference_pack_quantity', -1],
    'pack precision' => ['reference_pack_quantity', '1.12345'], 'pack overflow' => ['reference_pack_quantity', '10000000000'],
    'pack without quantity' => ['reference_pack_unit_code', 'kg', 'reference_pack_quantity'], 'unsupported pack' => ['reference_pack_unit_code', 'piece'],
    'application' => ['application_method', 'unsupported'], 'crop independent' => ['crop_id', 1], 'source wrong step' => ['source_id', 1],
    'status wrong step' => ['is_active', true], 'verification wrong step' => ['verification_status', 'verified'], 'workflow' => ['completed_step', 5],
    'foreign key' => ['fertilizer_id', 1], 'locale input' => ['locale', 'hi'],
]);

test('duplicate identifiers fail while self updates and nullable product codes remain valid', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['slug' => 'urea', 'fertilizer_code' => 'FRT-001']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload())->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload(['slug' => 'another', 'fertilizer_code' => 'FRT-001']))->assertUnprocessable()->assertJsonValidationErrors('fertilizer_code');
    $this->patchJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-1', ['slug' => 'urea', 'fertilizer_code' => 'FRT-001'])->assertOk()->assertJsonPath('data.id', $fertilizer->id);
    $this->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload(['slug' => 'second', 'fertilizer_code' => null]))->assertCreated();
    $this->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload(['slug' => 'third', 'fertilizer_code' => null]))->assertCreated();
    $this->assertDatabaseCount('fertilizers', 3);
});

test('packaging permits mass and volume units but rejects cross-dimension reference packs', function (string $unit, string $pack, bool $valid) {
    $admin = AdminUser::factory()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/step-1',
        fertilizerBasicPayload(['form' => 'liquid', 'default_unit_code' => $unit, 'reference_pack_quantity' => '1', 'reference_pack_unit_code' => $pack]));
    $valid ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors('reference_pack_unit_code');
})->with(['mass' => ['g', 'kg', true], 'volume' => ['l', 'ml', true], 'reverse volume' => ['ml', 'l', true], 'cross dimension' => ['kg', 'l', false]]);

test('meaningful basic edits reopen the same product as a draft while preserving child data', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady(['setup_status' => 'completed', 'completed_step' => 5, 'verification_status' => 'verified',
        'is_active' => true, 'requested_is_active' => true]);
    $image = FertilizerImage::factory()->for($fertilizer)->create();
    $nutrientId = $fertilizer->nutrients()->sole()->id;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-1', ['brand_name' => 'Reviewed brand'])
        ->assertOk()->assertJsonPath('data.id', $fertilizer->id)->assertJsonPath('data.setup_status', 'draft')
        ->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.is_active', false)->assertJsonPath('data.completed_step', 4)
        ->assertJsonPath('data.translations.hi.name', 'यूरिया')->assertJsonPath('data.images.0.id', $image->id);
    expect($fertilizer->nutrients()->sole()->id)->toBe($nutrientId);
    $this->assertDatabaseCount('fertilizers', 1);
});

test('step two synchronizes nutrient rows with stable IDs and preserves unrelated saved content', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $first = $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => [fertilizerNutrientRow(), fertilizerNutrientRow(['nutrient_code' => 'P2O5', 'content_value' => '0'])],
        'composition_notes' => 'Product-label values'])->assertOk()->assertJsonPath('data.completed_step', 2)->assertJsonPath('data.nutrients.0.content_value', '46.0000');
    $id = $first->json('data.nutrients.0.id');
    $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => [fertilizerNutrientRow(['content_value' => '45.5000'])]])->assertOk()
        ->assertJsonCount(1, 'data.nutrients')->assertJsonPath('data.nutrients.0.id', $id)->assertJsonPath('data.nutrients.0.content_value', '45.5000')
        ->assertJsonPath('data.composition_notes', 'Product-label values');
    $this->assertDatabaseCount('fertilizer_nutrients', 1);
    $this->assertDatabaseCount('fertilizer_translations', 2);
});

test('nutrient composition accepts different units explicit zeros and non-NPK nutrients', function (string $code, string $unit, string $value) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => [
        fertilizerNutrientRow(['nutrient_code' => $code, 'content_unit_code' => $unit, 'content_value' => $value])]])->assertOk()->assertJsonCount(1, 'data.nutrients');
    $this->assertDatabaseHas('fertilizer_nutrients', ['fertilizer_id' => $fertilizer->id, 'nutrient_code' => $code, 'content_unit_code' => $unit]);
})->with(['zero' => ['K2O', 'percent_ww', '0'], 'percent boundary' => ['N', 'percent_wv', '100'],
    'g per litre above percentage range' => ['N', 'g_per_l', '250.1250'], 'zinc' => ['Zn', 'percent_ww', '21'], 'organic carbon' => ['OC', 'percent_ww', '18']]);

test('empty composition is allowed only for applicable types without invented nutrient zeros', function (string $type, bool $valid) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create(['fertilizer_type' => $type]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => []]);
    $valid ? $response->assertOk()->assertJsonCount(0, 'data.nutrients') : $response->assertUnprocessable()->assertJsonValidationErrors('nutrients');
    $this->assertDatabaseCount('fertilizer_nutrients', 0);
})->with(['organic' => ['organic', true], 'bio' => ['biofertilizer', true], 'soil' => ['soil_amendment', true], 'other' => ['other', true],
    'straight' => ['straight', false], 'micronutrient' => ['micronutrient', false]]);

test('invalid nutrient rows fail atomically with nested field errors', function (array $rows, string $field) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => $rows])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('fertilizer_nutrients', 0);
    expect($fertilizer->fresh()->completed_step)->toBe(1);
})->with([
    'missing fields' => [[[]], 'nutrients.0'], 'bad code' => [[['nutrient_code' => 'Unknown', 'content_value' => '1', 'content_unit_code' => 'percent_ww']], 'nutrients.0.nutrient_code'],
    'bad unit' => [[['nutrient_code' => 'N', 'content_value' => '1', 'content_unit_code' => 'kg']], 'nutrients.0.content_unit_code'],
    'negative' => [[['nutrient_code' => 'N', 'content_value' => '-1', 'content_unit_code' => 'g_per_l']], 'nutrients.0.content_value'],
    'percentage overflow' => [[['nutrient_code' => 'N', 'content_value' => '100.0001', 'content_unit_code' => 'percent_ww']], 'nutrients.0.content_value'],
    'wv percentage overflow' => [[['nutrient_code' => 'N', 'content_value' => '101', 'content_unit_code' => 'percent_wv']], 'nutrients.0.content_value'],
    'precision' => [[['nutrient_code' => 'N', 'content_value' => '1.12345', 'content_unit_code' => 'percent_ww']], 'nutrients.0.content_value'],
    'decimal overflow' => [[['nutrient_code' => 'N', 'content_value' => '100000000', 'content_unit_code' => 'g_per_l']], 'nutrients.0.content_value'],
    'duplicate' => [[['nutrient_code' => 'N', 'content_value' => '1', 'content_unit_code' => 'percent_ww'], ['nutrient_code' => 'N', 'content_value' => '2', 'content_unit_code' => 'percent_ww']], 'nutrients.1.nutrient_code'],
    'foreign row key' => [[['fertilizer_id' => 99, 'nutrient_code' => 'N', 'content_value' => '1', 'content_unit_code' => 'percent_ww']], 'nutrients.0'],
]);

test('same nutrient in genuinely different content units is not a duplicate', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2',
        ['nutrients' => [fertilizerNutrientRow(), fertilizerNutrientRow(['content_unit_code' => 'g_per_l', 'content_value' => '250'])]])->assertOk()->assertJsonCount(2, 'data.nutrients');
    $this->assertDatabaseCount('fertilizer_nutrients', 2);
});

test('missing nutrient collections and unapproved root fields cannot erase saved composition', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['composition_notes' => 'Notes only'])
        ->assertUnprocessable()->assertJsonValidationErrors('nutrients');
    $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => [fertilizerNutrientRow()], 'is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->assertDatabaseCount('fertilizer_nutrients', 1);
});

test('translations require effective names in all configured languages and preserve omitted fields', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create(['completed_step' => 2]);
    $fertilizer->translations()->create(['locale' => 'en', 'name' => 'Urea', 'description' => 'Original description']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['en' => ['name' => 'Urea']]])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.hi.name');
    $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['hi' => ['name' => 'यूरिया', 'usage_instructions' => 'सत्यापित निर्देशों का पालन करें।']]])
        ->assertOk()->assertJsonPath('data.completed_step', 3)->assertJsonPath('data.translations.en.description', 'Original description')
        ->assertJsonPath('data.translations.hi.name', 'यूरिया');
    $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['hi' => ['description' => 'नई जानकारी']]])->assertOk()->assertJsonPath('data.translations.hi.name', 'यूरिया');
    $this->assertDatabaseCount('fertilizer_translations', 2);
});

test('translation input rejects unsupported locale keys extra nested keys and blank names', function (array $translations, string $field) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 2]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => $translations])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['locale' => [['fr' => ['name' => 'Unexpected']], 'translations'], 'extra' => [['hi' => ['name' => 'यूरिया', 'is_active' => true]], 'translations.hi'],
    'blank' => [['hi' => ['name' => '']], 'translations.hi.name']]);

test('mandatory locales come from shared language configuration rather than a fertilizer-specific list', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create(['completed_step' => 2]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['en' => ['name' => 'Urea']]])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.gu.name');
    $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['gu' => ['name' => 'યુરિયા']]])->assertOk()->assertJsonPath('data.translations.gu.name', 'યુરિયા');
});

test('steps cannot skip required predecessors and drafts cannot be activated', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $fertilizer = Fertilizer::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-3', ['translations' => ['en' => ['name' => 'Urea'], 'hi' => ['name' => 'यूरिया']]])->assertConflict();
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending'])->assertConflict();
    $this->patchJson('/api/admin/fertilizers/'.$fertilizer->id.'/status', ['is_active' => true])->assertConflict();
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')->assertUnprocessable();
    expect($fertilizer->fresh()->completed_step)->toBe(1);
    expect(Fertilizer::published()->count())->toBe(0);
});

test('media can save requested active status on a pending draft without publishing it', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $fertilizer = fertilizerReady(['completed_step' => 3]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4',
        ['verification_status' => 'pending', 'is_active' => true])->assertOk()->assertJsonPath('data.requested_is_active', true)
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.completed_step', 4);
    expect(Fertilizer::published()->count())->toBe(0);
});

test('verification requires reviewer authorization and selecting a source never verifies a product', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady(['completed_step' => 3]);
    $source = KnowledgeSource::factory()->active()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4',
        ['verification_status' => 'verified', 'source_id' => $source->id])->assertForbidden();
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'pending', 'source_id' => $source->id])
        ->assertOk()->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.source.id', $source->id);
    expect($source->fertilizers()->sole()->id)->toBe($fertilizer->id);
});

test('verified products accept an optional absent source but reject ineligible selected sources', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $fertilizer = fertilizerReady(['completed_step' => 3]);
    $source = KnowledgeSource::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4',
        ['verification_status' => 'verified', 'source_id' => $source->id])->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'verified', 'source_id' => null])->assertOk()->assertJsonPath('data.source_id', null);
});

test('finalization preserves ID and rows while duplicate finalization returns 409', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady();
    $nutrientId = $fertilizer->nutrients()->sole()->id;
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizers/'.$fertilizer->id.'/review')->assertOk()->assertJsonPath('data.ready_to_finalize', true);
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')->assertOk()->assertJsonPath('data.id', $fertilizer->id)
        ->assertJsonPath('data.setup_status', 'completed')->assertJsonPath('data.completed_step', 5)->assertJsonPath('data.next_step', null);
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')->assertConflict();
    $this->getJson('/api/admin/fertilizers/'.$fertilizer->id.'/review')->assertOk()->assertJsonPath('data.ready_to_finalize', false);
    $this->assertDatabaseCount('fertilizers', 1);
    $this->assertDatabaseCount('fertilizer_nutrients', 1);
    expect($fertilizer->nutrients()->sole()->id)->toBe($nutrientId);
});

test('active and verification remain independent in admin records while farmer publication requires verification', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $fertilizer = fertilizerReady(['requested_is_active' => true]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.verification_status', 'pending');
    expect(Fertilizer::published()->count())->toBe(0);
    $this->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-4', ['verification_status' => 'verified'])->assertOk()->assertJsonPath('data.is_active', true);
    expect(Fertilizer::published()->count())->toBe(1);
    $this->patchJson('/api/admin/fertilizers/'.$fertilizer->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.verification_status', 'verified');
    expect(Fertilizer::published()->count())->toBe(0);
});

test('finalization catches missing translations and invalid stored composition without fabricating missing data', function (string $kind, string $field) {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady();
    match ($kind) {
        'translation' => $fertilizer->translations()->where('locale', 'hi')->delete(),
        'composition' => $fertilizer->nutrients()->delete(), 'basic' => $fertilizer->forceFill(['default_unit_code' => 'plant'])->save()
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/fertilizers/'.$fertilizer->id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($fertilizer->fresh()->setup_status)->toBe('draft');
    $this->assertDatabaseCount('fertilizers', 1);
})->with(['translation' => ['translation', 'translations.hi.name'], 'composition' => ['composition', 'nutrients'], 'basic' => ['basic', 'default_unit_code']]);

test('listing localizes names and searches names codes brands and manufacturers', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations('Urea', 'यूरिया')->create(['fertilizer_code' => 'FRT-001', 'brand_name' => 'IFFCO', 'manufacturer_name' => 'Producer One']);
    Fertilizer::factory()->withTranslations('Other fertilizer', 'अन्य उर्वरक')->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/fertilizers?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $fertilizer->id)->assertJsonPath('data.0.name', 'यूरिया');
})->with(['Urea', 'यूरिया', 'FRT-001', 'IFFCO', 'Producer One']);

test('each listing filter independently excludes different products', function (string $field) {
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations()->create();
    Fertilizer::factory()->withTranslations()->create(match ($field) {
        'fertilizer_type' => ['fertilizer_type' => 'organic'], 'form' => ['form' => 'liquid'],
        'verification_status' => ['verification_status' => 'verified'], 'is_active' => ['is_active' => true], 'setup_status' => ['setup_status' => 'completed'],
    });
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizers?'.http_build_query([$field => $fertilizer->{$field}]))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $fertilizer->id);
})->with(['fertilizer_type', 'form', 'verification_status', 'is_active', 'setup_status']);

test('listing returns nutrient content and the first image without full galleries or translation payloads', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady();
    $first = FertilizerImage::factory()->for($fertilizer)->create();
    FertilizerImage::factory()->for($fertilizer)->create(['sort_order' => 2]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/fertilizers')
        ->assertOk()->assertJsonPath('data.0.primary_image.id', $first->id)->assertJsonPath('data.0.nutrients.0.content_value', '46.0000')
        ->assertJsonMissingPath('data.0.images')->assertJsonMissingPath('data.0.translations')->assertJsonMissingPath('data.0.source');
    $this->getJson('/api/admin/fertilizers?per_page=101&page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
});

test('listing query counts stay bounded as fertilizers and nutrients increase', function () {
    $admin = AdminUser::factory()->create();
    fertilizerReady();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/fertilizers')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    foreach (range(1, 10) as $index) {
        fertilizerReady();
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/fertilizers')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('nutrient persistence failure rolls back earlier row edits and wizard progress', function () {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->create();
    $nutrient = FertilizerNutrient::factory()->for($fertilizer)->create();
    DB::unprepared("CREATE TRIGGER fail_fertilizer_nutrient BEFORE INSERT ON fertilizer_nutrients BEGIN SELECT RAISE(ABORT, 'Simulated nutrient failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2',
            ['nutrients' => [fertilizerNutrientRow(['content_value' => '40']), fertilizerNutrientRow(['nutrient_code' => 'S'])]])->assertInternalServerError();
        expect($nutrient->fresh()->content_value)->toBe('46.0000');
        expect($fertilizer->fresh()->completed_step)->toBe(1);
        $this->assertDatabaseCount('fertilizer_nutrients', 1);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_fertilizer_nutrient');
    }
});

test('historically referenced nutrient rows cannot be changed removed or cascade-deleted with a fertilizer', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady(['fertilizer_type' => 'organic']);
    $nutrient = $fertilizer->nutrients()->sole();
    Schema::create('fertilizer_nutrient_history_probe', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('fertilizer_nutrient_id')->constrained('fertilizer_nutrients')->cascadeOnDelete();
    });
    DB::table('fertilizer_nutrient_history_probe')->insert(['fertilizer_nutrient_id' => $nutrient->id]);
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => [fertilizerNutrientRow(['content_value' => '40'])]])->assertConflict();
        $this->putJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-2', ['nutrients' => []])->assertConflict();
        $this->deleteJson('/api/admin/fertilizers/'.$fertilizer->id)->assertConflict();
        expect($nutrient->fresh()->content_value)->toBe('46.0000');
        $this->assertDatabaseCount('fertilizer_nutrient_history_probe', 1);
    } finally {
        Schema::dropIfExists('fertilizer_nutrient_history_probe');
    }
});

test('active and historically referenced fertilizers cannot be deleted', function () {
    $admin = AdminUser::factory()->create();
    $fertilizer = fertilizerReady();
    $active = Fertilizer::factory()->create(['is_active' => true]);
    Schema::create('fertilizer_expense_history_probe', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('fertilizer_id')->constrained()->cascadeOnDelete();
    });
    DB::table('fertilizer_expense_history_probe')->insert(['fertilizer_id' => $fertilizer->id]);
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/fertilizers/'.$fertilizer->id)->assertConflict();
        $this->deleteJson('/api/admin/fertilizers/'.$active->id)->assertConflict();
        $this->assertModelExists($fertilizer);
        $this->assertDatabaseCount('fertilizer_expense_history_probe', 1);
    } finally {
        Schema::dropIfExists('fertilizer_expense_history_probe');
    }
});

test('all five steps finalize and reopen one biofertilizer without inventing nutrient rows', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $id = $this->postJson('/api/admin/fertilizers/step-1', fertilizerBasicPayload(['name' => 'Bio product', 'slug' => 'bio-product',
        'fertilizer_type' => 'biofertilizer', 'form' => 'liquid', 'default_unit_code' => 'l']))->assertCreated()->json('data.id');

    $this->putJson('/api/admin/fertilizers/'.$id.'/step-2', ['nutrients' => [], 'composition_notes' => 'No conventional NPK analysis supplied.'])
        ->assertOk()->assertJsonPath('data.completed_step', 2);
    $this->putJson('/api/admin/fertilizers/'.$id.'/step-3', ['translations' => ['hi' => ['name' => 'जैव उत्पाद']]])
        ->assertOk()->assertJsonPath('data.completed_step', 3);
    $this->postJson('/api/admin/fertilizers/'.$id.'/step-4', ['verification_status' => 'verified', 'is_active' => true])
        ->assertOk()->assertJsonPath('data.completed_step', 4)->assertJsonPath('data.is_active', false);
    $this->getJson('/api/admin/fertilizers/'.$id.'/review')->assertOk()->assertJsonPath('data.ready_to_finalize', true);
    $this->postJson('/api/admin/fertilizers/'.$id.'/finalize')->assertOk()->assertJsonPath('data.completed_step', 5)->assertJsonPath('data.is_active', true);
    expect(Fertilizer::published()->sole()->id)->toBe($id);

    $this->patchJson('/api/admin/fertilizers/'.$id.'/step-1', ['brand_name' => 'Updated brand'])->assertOk()
        ->assertJsonPath('data.setup_status', 'draft')->assertJsonPath('data.verification_status', 'pending');
    $this->postJson('/api/admin/fertilizers/'.$id.'/finalize')->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.is_active', true);
    expect(Fertilizer::published()->count())->toBe(0);
    $this->postJson('/api/admin/fertilizers/'.$id.'/step-4', ['verification_status' => 'verified'])->assertOk();
    expect(Fertilizer::published()->sole()->id)->toBe($id);
    $this->assertDatabaseCount('fertilizers', 1);
    $this->assertDatabaseCount('fertilizer_nutrients', 0);
    $this->assertDatabaseCount('fertilizer_translations', 2);
});

test('pagination preserves literal filters and orders equal localized names by stable IDs', function () {
    $admin = AdminUser::factory()->create();
    $products = Fertilizer::factory()->withTranslations('Shared name', 'समान नाम')->count(3)->create();
    Fertilizer::factory()->withTranslations()->create(['form' => 'liquid']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);

    $response = $this->getJson('/api/admin/fertilizers?form=granular&is_active=0&per_page=2')->assertOk()
        ->assertJsonPath('pagination.total', 3)->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $products[0]->id)
        ->assertJsonPath('data.1.id', $products[1]->id);
    expect($response->json('pagination.next_page_url'))->toContain('form=granular', 'is_active=0', 'per_page=2', 'page=2');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $products[2]->id);
});

test('header locale resolves English fallback while primary-name edits always update the default language', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $fertilizer = Fertilizer::factory()->withTranslations('Urea', 'यूरिया')->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'gu')
        ->getJson('/api/admin/fertilizers/'.$fertilizer->id)->assertOk()->assertJsonPath('data.name', 'Urea');
    $this->withHeader('X-Locale', 'hi')->patchJson('/api/admin/fertilizers/'.$fertilizer->id.'/step-1', ['name' => 'Updated primary name'])
        ->assertOk()->assertJsonPath('data.name', 'यूरिया')->assertJsonPath('data.translations.en.name', 'Updated primary name');
    $this->assertDatabaseCount('fertilizer_translations', 2);
});

test('nutrient constraints protect percentages outside HTTP while allowing g per litre values above 100', function () {
    $fertilizer = Fertilizer::factory()->create();
    $nutrient = FertilizerNutrient::factory()->for($fertilizer)->create();
    try {
        DB::table('fertilizer_nutrients')->where('id', $nutrient->id)->update(['content_value' => '100.0001']);
        $this->fail('The invalid percentage was accepted.');
    } catch (QueryException) {
        expect($nutrient->fresh()->content_value)->toBe('46.0000');
    }
    $nutrient->update(['content_unit_code' => 'g_per_l', 'content_value' => '250.1250']);
    expect($nutrient->fresh()->content_value)->toBe('250.1250');
});

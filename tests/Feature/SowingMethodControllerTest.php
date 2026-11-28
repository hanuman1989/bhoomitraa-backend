<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\FarmCropCycle;
use App\Models\SowingMethod;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function sowingBasicPayload(array $overrides = []): array
{
    return array_replace(['code' => 'line_sowing', 'slug' => 'line-sowing', 'title' => 'Line Sowing',
        'establishment_type' => 'direct_sowing', 'crop_scope' => 'all',
        'supports_row_spacing' => true, 'supports_plant_spacing' => false, 'supports_field_layout' => null], $overrides);
}

function sowingAdminToken(): string
{
    return AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
}

test('four steps persist one method and finalize with complete English Hindi content and crop applicability', function () {
    $crop = Crop::factory()->published()->create();
    $this->withToken(sowingAdminToken());
    $response = $this->postJson('/api/admin/sowing-methods', sowingBasicPayload(['crop_scope' => 'specific', 'crop_ids' => [$crop->id]]))
        ->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.is_complete', false)
        ->assertJsonPath('data.cover_image', null)->assertJsonPath('data.supports_row_spacing', true)
        ->assertJsonPath('data.supports_plant_spacing', false)->assertJsonPath('data.supports_field_layout', null)
        ->assertJsonPath('data.translations.en.title', 'Line Sowing');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $id = $response->json('data.id');
    $this->patchJson('/api/admin/sowing-methods/'.$id.'/step-2', ['translations' => [
        'en' => ['short_description' => 'Sowing in rows.', 'description' => 'Detailed English content.'],
        'hi' => ['title' => 'कतार बुवाई', 'short_description' => 'कतारों में बुवाई की विधि।', 'description' => 'बुवाई विधि का विस्तृत विवरण।', 'advantages' => '   '],
    ]])->assertOk()->assertJsonPath('data.translations.hi.title', 'कतार बुवाई')->assertJsonPath('data.translations.hi.advantages', null);
    $this->postJson('/api/admin/sowing-methods/'.$id.'/step-3')->assertOk()->assertJsonCount(0, 'data.images');
    $this->getJson('/api/admin/sowing-methods/'.$id)->assertOk()->assertJsonPath('data.is_complete', true)->assertJsonPath('data.readiness_errors', []);
    $this->postJson('/api/admin/sowing-methods/'.$id.'/finalize', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseCount('sowing_methods', 1);
    $this->assertDatabaseCount('sowing_method_translations', 2);
    $this->assertDatabaseHas('sowing_method_crops', ['sowing_method_id' => $id, 'crop_id' => $crop->id]);
    expect(SowingMethod::query()->published()->applicableToCrop($crop->id)->sole()->id)->toBe($id);
    expect($crop->sowingMethods()->sole()->id)->toBe($id);
});

test('returns 401 for every unauthenticated management endpoint', function (string $verb, string $suffix) {
    $method = SowingMethod::factory()->create();
    $this->json($verb, '/api/admin/sowing-methods'.str_replace('{id}', (string) $method->id, $suffix))
        ->assertUnauthorized()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with([
    ['GET', ''], ['GET', '/options'], ['POST', ''], ['GET', '/{id}'], ['PATCH', '/{id}'],
    ['PATCH', '/{id}/step-2'], ['POST', '/{id}/step-3'], ['POST', '/{id}/finalize'], ['PATCH', '/{id}/status'], ['DELETE', '/{id}'],
]);

test('rejects farmer tokens inactive admins and missing admin abilities', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'wrong ability' ? 'frontend' : 'admin'])->plainTextToken)
        ->getJson('/api/admin/sowing-methods');
    if ($kind === 'farmer') {
        $response->assertUnauthorized();
    } else {
        $response->assertForbidden();
    }
})->with(['farmer', 'inactive', 'wrong ability']);

test('returns 403 when the module gate denies an authenticated admin', function () {
    $this->withToken(sowingAdminToken());
    Gate::define('manage-sowing-methods', fn (): bool => false);

    $this->getJson('/api/admin/sowing-methods')->assertForbidden();
    $this->postJson('/api/admin/sowing-methods', sowingBasicPayload())->assertForbidden();
    $this->assertDatabaseCount('sowing_methods', 0);
});

test('returns 422 with field message lists when required basic fields are missing', function () {
    $response = $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['code', 'slug', 'title', 'establishment_type', 'crop_scope'])
        ->assertJsonPath('errors.code.0', 'The code field is required.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('sowing_methods', 0);
});

test('rejects invalid basic values with 422 without creating any records', function (string $field, mixed $value) {
    $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods', sowingBasicPayload([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('sowing_methods', 0);
    $this->assertDatabaseCount('sowing_method_translations', 0);
})->with([
    ['code', 'Invalid-Code'], ['code', str_repeat('a', 41)], ['slug', 'Bad Slug'], ['slug', str_repeat('a', 181)],
    ['title', '   '], ['title', str_repeat('a', 201)], ['establishment_type', 'invalid'], ['crop_scope', 'invalid'],
    ['machinery_requirement', 'medium'], ['labour_requirement', 'required'], ['cost_level', 'expensive'],
    ['sort_order', -1], ['sort_order', 65536], ['sort_order', 1.5], ['supports_row_spacing', 'yes'],
    ['supports_plant_spacing', []], ['supports_field_layout', 'unknown'], ['is_active', 'yes'],
    ['completed_step', 4], ['translations', ['en' => ['title' => 'Injected']]],
]);

test('preserves all three nullable boolean states during partial updates', function (mixed $value) {
    $method = SowingMethod::factory()->withTranslations()->create();
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id, [
        'supports_row_spacing' => $value, 'supports_plant_spacing' => $value, 'supports_field_layout' => $value,
    ])->assertOk()->assertJsonPath('data.supports_row_spacing', $value)->assertJsonPath('data.supports_plant_spacing', $value)
        ->assertJsonPath('data.supports_field_layout', $value);
    expect($method->fresh()->supports_field_layout)->toBe($value);
})->with([true, false, null]);

test('returns 422 for premature activation on creation and rolls back the English translation', function () {
    $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods', sowingBasicPayload(['is_active' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors(['translations.hi', 'translations.en.description']);
    $this->assertDatabaseCount('sowing_methods', 0);
    $this->assertDatabaseCount('sowing_method_translations', 0);
});

test('rejects duplicate identifiers but accepts unchanged unique values on update', function (string $field) {
    $method = SowingMethod::factory()->withTranslations()->create();
    $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods', sowingBasicPayload([$field => $method->{$field}]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/admin/sowing-methods/'.$method->id, [$field => $method->{$field}, 'sort_order' => 65535])
        ->assertOk()->assertJsonPath('data.sort_order', 65535);
    $this->assertDatabaseCount('sowing_methods', 1);
})->with(['code', 'slug']);

test('specific scope permits inactive drafts but requires crops on finalization even when remaining inactive', function () {
    $method = SowingMethod::factory()->withTranslations()->create(['crop_scope' => 'specific']);
    $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods/'.$method->id.'/finalize', ['is_active' => false])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_ids');
    $this->patchJson('/api/admin/sowing-methods/'.$method->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_ids');
    expect($method->fresh()->is_active)->toBeFalse();
});

test('rejects invalid duplicate inactive and unrelated all-scope crop selections', function (string $kind) {
    $crop = Crop::factory()->published()->create();
    $inactive = Crop::factory()->create();
    $payload = match ($kind) {
        'invalid' => ['crop_scope' => 'specific', 'crop_ids' => [999999]],
        'duplicate' => ['crop_scope' => 'specific', 'crop_ids' => [$crop->id, $crop->id]],
        'inactive' => ['crop_scope' => 'specific', 'crop_ids' => [$inactive->id]],
        'all' => ['crop_scope' => 'all', 'crop_ids' => [$crop->id]],
    };
    $this->withToken(sowingAdminToken())->postJson('/api/admin/sowing-methods', sowingBasicPayload($payload))->assertUnprocessable();
    $this->assertDatabaseCount('sowing_methods', 0);
    $this->assertDatabaseCount('sowing_method_crops', 0);
})->with(['invalid', 'duplicate', 'inactive', 'all']);

test('switches crop scopes atomically and preserves omitted crops and English translation fields', function () {
    $crop = Crop::factory()->published()->create();
    $method = SowingMethod::factory()->withTranslations()->create();
    $english = $method->translations()->where('locale', 'en')->first();
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id, ['crop_scope' => 'specific', 'crop_ids' => [$crop->id]])
        ->assertOk()->assertJsonCount(1, 'data.crops');
    $this->patchJson('/api/admin/sowing-methods/'.$method->id, ['title' => 'New title'])->assertOk()
        ->assertJsonCount(1, 'data.crops')->assertJsonPath('data.translations.en.description', $english->description);
    $this->patchJson('/api/admin/sowing-methods/'.$method->id, ['crop_scope' => 'all'])->assertOk()->assertJsonCount(0, 'data.crops');
    $this->assertDatabaseCount('sowing_method_crops', 0);
    $this->assertDatabaseCount('sowing_method_translations', 2);
});

test('validates translation locale keys nested fields and lengths', function (array $translations, string $field) {
    $method = SowingMethod::factory()->withTranslations()->create();
    $before = $method->translations()->get()->toArray();
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id.'/step-2', ['translations' => $translations])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($method->translations()->get()->toArray())->toBe($before);
})->with([
    [['fr' => ['title' => 'French']], 'translations'],
    [[['locale' => 'en', 'title' => 'First'], ['locale' => 'en', 'title' => 'Duplicate']], 'translations'],
    [['hi' => ['title' => '　  ']], 'translations.hi.title'],
    [['hi' => ['title' => str_repeat('क', 201)]], 'translations.hi.title'],
    [['en' => ['short_description' => str_repeat('a', 501)]], 'translations.en.short_description'],
    [['en' => ['description' => str_repeat('क', 16001)]], 'translations.en.description'],
    [['en' => ['sowing_method_id' => 999]], 'translations.en'],
]);

test('rejects incomplete activation and active translation edits without losing earlier writes', function () {
    $method = SowingMethod::factory()->active()->create();
    $before = $method->translations()->get()->toArray();
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id.'/step-2', ['translations' => [
        'en' => ['title' => 'Should roll back'], 'hi' => ['description' => null],
    ]])->assertUnprocessable()->assertJsonValidationErrors('translations.hi.description');
    expect($method->translations()->get()->toArray())->toBe($before);
    $this->patchJson('/api/admin/sowing-methods/'.$method->id, ['crop_scope' => 'specific'])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_ids');
    expect($method->fresh()->crop_scope)->toBe('all');
    $this->patchJson('/api/admin/sowing-methods/'.$method->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->patchJson('/api/admin/sowing-methods/'.$method->id.'/step-2', ['translations' => ['hi' => ['description' => null]]])->assertOk();
    $this->patchJson('/api/admin/sowing-methods/'.$method->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.hi.description');
    expect($method->fresh()->is_active)->toBeFalse();
    expect(SowingMethod::query()->published()->count())->toBe(0);
});

test('guards code changes and deletion for current and nested historical references', function (string $kind) {
    $method = SowingMethod::factory()->withTranslations()->create(['code' => 'line_sowing']);
    if ($kind === 'seed rate') {
        $reference = CropSeedRate::factory()->create(['sowing_method' => $method->code]);
        expect($reference->sowingMethod->id)->toBe($method->id);
    } else {
        $cycle = FarmCropCycle::factory()->create(['sowing_method' => $kind === 'cycle' ? $method->code : null,
            'planning_snapshot' => $kind === 'snapshot' ? ['seed' => ['sowing_method' => $method->code]] : null]);
        if ($kind === 'draft') {
            $cycle->editDraft()->create(['draft_data' => ['attributes' => ['sowing_method' => $method->code]],
                'wizard_progress' => [], 'base_revision' => 1, 'revision' => 1]);
        }
        if ($kind === 'cycle') {
            expect($cycle->sowingMethod->id)->toBe($method->id);
        }
    }
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id, ['code' => 'new_code'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->deleteJson('/api/admin/sowing-methods/'.$method->id)->assertConflict()
        ->assertJsonPath('message', 'Referenced sowing methods cannot be deleted. Deactivate the method instead.');
    expect($method->fresh()->code)->toBe('line_sowing');
    $this->patchJson('/api/admin/sowing-methods/'.$method->id.'/status', ['is_active' => false])->assertOk();
})->with(['cycle', 'seed rate', 'draft', 'snapshot']);

test('allows unreferenced code changes and deletes translations and crop pivots', function () {
    $crop = Crop::factory()->published()->create();
    $method = SowingMethod::factory()->withTranslations()->create(['crop_scope' => 'specific']);
    $method->crops()->attach($crop);
    $this->withToken(sowingAdminToken())->patchJson('/api/admin/sowing-methods/'.$method->id, ['code' => 'changed_code'])
        ->assertOk()->assertJsonPath('data.code', 'changed_code');
    $this->deleteJson('/api/admin/sowing-methods/'.$method->id)->assertOk();
    $this->assertModelMissing($method);
    $this->assertDatabaseCount('sowing_method_translations', 0);
    $this->assertDatabaseCount('sowing_method_crops', 0);
    $this->assertModelExists($crop);
});

test('paginates deterministic summaries and searches both languages with literal wildcards', function () {
    $first = SowingMethod::factory()->withTranslations()->create(['code' => 'line_sowing', 'slug' => 'line-sowing', 'sort_order' => 2]);
    $second = SowingMethod::factory()->withTranslations()->create(['sort_order' => 2]);
    $first->translations()->where('locale', 'en')->update(['title' => 'A 100%_! method']);
    $first->translations()->where('locale', 'hi')->update(['title' => 'विशिष्ट कतार']);
    $second->translations()->where('locale', 'en')->update(['title' => 'Z method']);
    $this->withToken(sowingAdminToken())->getJson('/api/admin/sowing-methods?per_page=1')
        ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('pagination.total', 2)
        ->assertJsonMissingPath('data.0.translations')->assertJsonMissingPath('data.0.images');
    foreach (['100%_!', 'विशिष्ट', 'line_sowing', 'line-sowing'] as $keyword) {
        $this->getJson('/api/admin/sowing-methods?keyword='.urlencode($keyword))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    }
    $this->getJson('/api/admin/sowing-methods?sort_by=title&sort_direction=desc')->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/admin/sowing-methods?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/admin/sowing-methods?page=9')->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.from', null);
});

test('filters crop applicability including all-crops methods and controlled characteristics', function () {
    $crop = Crop::factory()->published()->create();
    $all = SowingMethod::factory()->create();
    $specific = SowingMethod::factory()->create(['crop_scope' => 'specific', 'machinery_requirement' => 'required', 'labour_requirement' => 'high', 'cost_level' => 'high']);
    $specific->crops()->attach($crop);
    SowingMethod::factory()->create(['crop_scope' => 'specific']);
    $this->withToken(sowingAdminToken())->getJson('/api/admin/sowing-methods?crop_id='.$crop->id)
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
    $this->getJson('/api/admin/sowing-methods?crop_id='.$crop->id.'&machinery_requirement=required&labour_requirement=high&cost_level=high&establishment_type=direct_sowing&is_active=0&crop_scope=specific')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $specific->id);
    expect(SowingMethod::query()->applicableToCrop($crop->id)->pluck('id')->all())->toContain($all->id, $specific->id);
});

test('returns 422 for unsafe sorting and invalid pagination filters', function (string $query) {
    $this->withToken(sowingAdminToken())->getJson('/api/admin/sowing-methods?'.$query)->assertUnprocessable();
})->with(['sort_by=code%3Bdrop', 'sort_direction=sideways', 'page=0', 'per_page=101', 'per_page=-1', 'establishment_type=invalid', 'crop_id=999999']);

test('options use static routes and existing locales while missing records return 404', function () {
    $this->withToken(sowingAdminToken())->getJson('/api/admin/sowing-methods/options')->assertOk()
        ->assertJsonPath('data.establishment_types.0', ['value' => 'direct_sowing', 'label' => 'Direct Sowing'])
        ->assertJsonPath('data.supported_locales.1.value', 'hi')->assertJsonCount(4, 'data.machinery_requirements');
    $this->getJson('/api/admin/sowing-methods/999999')->assertNotFound();
});

test('resolves Hindi titles with English fallback and honors the established header precedence', function () {
    $method = SowingMethod::factory()->withTranslations()->create();
    $method->translations()->where('locale', 'en')->update(['title' => 'English title']);
    $this->withToken(sowingAdminToken())->withHeaders(['X-Locale' => 'hi'])->getJson('/api/admin/sowing-methods')
        ->assertOk()->assertJsonPath('data.0.title', 'कतार बुवाई');
    $method->translations()->where('locale', 'hi')->delete();
    $this->getJson('/api/admin/sowing-methods')->assertOk()->assertJsonPath('data.0.title', 'English title');
    $this->withHeaders(['X-Locale' => 'unsupported', 'Accept-Language' => 'hi'])->getJson('/api/admin/sowing-methods')
        ->assertOk()->assertJsonPath('data.0.title', 'English title');
});

test('future published queries exclude methods whose translations or associated crop eligibility became incomplete', function () {
    $crop = Crop::factory()->published()->create();
    $method = SowingMethod::factory()->active()->create(['crop_scope' => 'specific']);
    $method->crops()->attach($crop);
    expect(SowingMethod::query()->published()->count())->toBe(1);
    $crop->category()->update(['is_active' => false]);
    expect(SowingMethod::query()->published()->count())->toBe(0);
    $crop->category()->update(['is_active' => true]);
    $method->translations()->where('locale', 'hi')->update(['description' => null]);
    expect(SowingMethod::query()->published()->count())->toBe(0);
});

test('eager loads summary relationships without adding queries as a page grows', function () {
    $crop = Crop::factory()->published()->create();
    $first = SowingMethod::factory()->withTranslations()->create(['crop_scope' => 'specific']);
    $first->crops()->attach($crop);
    $this->withToken(sowingAdminToken());
    $catalogueQueries = fn (): int => collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/sowing_method|\bcrops\b|crop_translations/', $query['query']) === 1)->count();
    DB::enableQueryLog();
    $this->getJson('/api/admin/sowing-methods')->assertOk()->assertJsonCount(1, 'data');
    $singleQueries = $catalogueQueries();
    DB::disableQueryLog();
    foreach (SowingMethod::factory()->withTranslations()->count(3)->create(['crop_scope' => 'specific']) as $method) {
        $method->crops()->attach($crop);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson('/api/admin/sowing-methods')->assertOk()->assertJsonCount(4, 'data');
    expect($catalogueQueries())->toBe($singleQueries);
    DB::disableQueryLog();
});

test('uses exactly the approved columns and restrictive crop relationship', function () {
    expect(Schema::getColumnListing('sowing_methods'))->toHaveCount(15);
    expect(Schema::getColumnListing('sowing_method_translations'))->toHaveCount(14);
    expect(Schema::getColumnListing('sowing_method_images'))->toBe(['id', 'sowing_method_id', 'image', 'sort_order', 'created_at', 'updated_at']);
    expect(Schema::getColumnListing('sowing_method_crops'))->toBe(['sowing_method_id', 'crop_id']);
    $crop = Crop::factory()->create();
    $method = SowingMethod::factory()->create(['crop_scope' => 'specific']);
    $method->crops()->attach($crop);
    expect(fn () => DB::table('crops')->where('id', $crop->id)->delete())->toThrow(QueryException::class);
});

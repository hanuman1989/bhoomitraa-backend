<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropFieldLayout;
use App\Models\CropVariety;
use App\Models\SowingMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function fieldLayoutToken(): string
{
    return AdminUser::factory()->create()->createToken('test', ['admin'])->plainTextToken;
}

/** @return array<string, mixed> */
function fieldLayoutBasic(array $overrides = []): array
{
    return array_replace(['crop_scope' => 'all', 'layout_type' => 'random_distribution', 'sort_order' => 0, 'is_active' => false], $overrides);
}

/** @return array<string, array<string, string>> */
function fieldLayoutTranslations(): array
{
    return ['en' => ['title' => 'Test row layout', 'description' => 'Synthetic fixture, not agricultural advice.'],
        'hi' => ['title' => 'परीक्षण कतार व्यवस्था', 'description' => 'केवल परीक्षण के लिए विवरण।']];
}

test('persists and resumes four steps and activates only after successful finalization', function () {
    $this->withToken(fieldLayoutToken());
    $created = $this->postJson('/api/admin/crop-field-layouts/step-1', fieldLayoutBasic(['is_active' => true]))
        ->assertCreated()->assertJsonPath('data.is_active', false)->assertJsonPath('data.wizard.requested_is_active', true)
        ->assertJsonPath('data.wizard.completed_step', 1);
    expect(array_keys($created->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $id = $created->json('data.id');
    expect(DB::table('crop_field_layouts')->where('id', $id)->value('configuration'))->toBe('{}');
    $this->assertDatabaseCount('crop_field_layout_translations', 0);
    $this->postJson('/api/admin/crop-field-layouts/'.$id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors('completed_step');

    $this->putJson('/api/admin/crop-field-layouts/'.$id.'/step-2', ['configuration' => (object) [], 'reference_note' => 'Synthetic fixture.'])
        ->assertOk()->assertJsonPath('data.wizard.completed_step', 2)->assertJsonPath('data.is_active', false);
    $this->getJson('/api/admin/crop-field-layouts/'.$id)->assertOk()->assertJsonPath('data.reference_note', 'Synthetic fixture.')
        ->assertJsonPath('data.wizard.completed_step', 2);
    $this->putJson('/api/admin/crop-field-layouts/'.$id.'/step-3', ['translations' => fieldLayoutTranslations()])
        ->assertOk()->assertJsonPath('data.wizard.completed_step', 3)->assertJsonPath('data.wizard.can_finalize', true)
        ->assertJsonPath('data.translations.hi.title', 'परीक्षण कतार व्यवस्था');
    $before = CropFieldLayout::findOrFail($id)->getRawOriginal();
    $this->getJson('/api/admin/crop-field-layouts/'.$id.'/review')->assertOk()->assertJsonPath('data.wizard.can_finalize', true)
        ->assertJsonPath('data.basic_details.requested_is_active', true);
    expect(CropFieldLayout::findOrFail($id)->getRawOriginal())->toBe($before);
    $this->postJson('/api/admin/crop-field-layouts/'.$id.'/finalize')->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.wizard.is_finalized', true)->assertJsonPath('data.wizard.completed_step', 4);
    $this->postJson('/api/admin/crop-field-layouts/'.$id.'/finalize')->assertOk();
    $this->assertDatabaseCount('crop_field_layouts', 1);
    $this->assertDatabaseCount('crop_field_layout_translations', 2);
    $this->assertDatabaseHas('crop_field_layouts', ['id' => $id, 'is_active' => true, 'wizard_draft' => null]);
});

dataset('field layout endpoints', [
    ['GET', ''], ['GET', '/options'], ['POST', '/step-1'], ['GET', '/{id}'], ['PUT', '/{id}/step-1'],
    ['PUT', '/{id}/step-2'], ['PUT', '/{id}/step-3'], ['GET', '/{id}/review'], ['POST', '/{id}/finalize'], ['DELETE', '/{id}'],
]);

test('returns 401 for every unauthenticated endpoint', function (string $verb, string $suffix) {
    $layout = CropFieldLayout::factory()->create();

    $this->json($verb, '/api/admin/crop-field-layouts'.str_replace('{id}', (string) $layout->id, $suffix))
        ->assertUnauthorized()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with('field layout endpoints');

test('returns 401 for frontend tokens even with the admin ability', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-field-layouts')->assertUnauthorized();
});

test('returns 403 for inactive admins or missing admin abilities', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)->getJson('/api/admin/crop-field-layouts')->assertForbidden();
})->with([[false, ['admin']], [true, ['frontend']]]);

test('returns 403 for every endpoint when the module gate refuses access', function (string $verb, string $suffix) {
    $layout = CropFieldLayout::factory()->create();
    $this->withToken(fieldLayoutToken());
    Gate::define('manage-crop-field-layouts', fn (): bool => false);

    $this->json($verb, '/api/admin/crop-field-layouts'.str_replace('{id}', (string) $layout->id, $suffix))->assertForbidden();
    $this->assertModelExists($layout);
})->with('field layout endpoints');

test('returns 422 with field message lists for missing basic details without an Accept header', function () {
    $response = $this->withToken(fieldLayoutToken())->post('/api/admin/crop-field-layouts/step-1', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['crop_scope', 'layout_type', 'sort_order'])
        ->assertJsonPath('errors.crop_scope.0', 'The crop scope field is required.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('crop_field_layouts', 0);
});

test('returns 404 for missing records', function () {
    $this->withToken(fieldLayoutToken())->getJson('/api/admin/crop-field-layouts/999999')->assertNotFound()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

test('returns 422 for unsupported basic values and protected payload fields', function (string $key, mixed $value) {
    $this->withToken(fieldLayoutToken())->postJson('/api/admin/crop-field-layouts/step-1', fieldLayoutBasic([$key => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($key);
    $this->assertDatabaseCount('crop_field_layouts', 0);
})->with([
    ['layout_type', 'custom'], ['layout_type', 'paired_row'], ['crop_scope', 'unknown'], ['sort_order', -1],
    ['sort_order', 65536], ['sort_order', 1.5], ['is_active', 'yes'], ['farming_type', 'orchard'],
    ['growing_system', 'rack_system'], ['crop_ids', '1'], ['sowing_method_id', 999999],
    ['title', 'Injected'], ['translations', ['en' => ['title' => 'Injected']]], ['configuration_version', 200],
    ['wizard_draft', ['completed_steps' => [1, 2, 3]]], ['region_id', 1], ['season_id', 1], ['image', 'file.png'],
]);

test('rejects unavailable crops duplicate selections and invalid variety ownership', function (string $kind, string $error) {
    $crop = Crop::factory()->published()->create();
    $other = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->create(['is_active' => true]);
    $input = fieldLayoutBasic(['crop_scope' => 'specific', 'crop_ids' => [$crop->id], 'crop_variety_id' => $variety->id]);
    match ($kind) {
        'missing' => $input['crop_ids'] = [],
        'invalid' => $input['crop_ids'] = [999999],
        'duplicate' => $input['crop_ids'] = [$crop->id, $crop->id],
        'inactive' => $crop->update(['is_active' => false]),
        'inactive category' => $crop->category->forceFill(['is_active' => false])->save(),
        'wrong variety' => $input['crop_ids'] = [$other->id],
        'multiple variety' => $input['crop_ids'] = [$crop->id, $other->id],
        default => $variety->update(['is_active' => false]),
    };

    $this->withToken(fieldLayoutToken())->postJson('/api/admin/crop-field-layouts/step-1', $input)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('crop_field_layouts', 0);
    $this->assertDatabaseCount('crop_field_layout_crops', 0);
})->with([
    ['missing', 'crop_ids'], ['invalid', 'crop_ids'], ['duplicate', 'crop_ids.1'], ['inactive', 'crop_ids'],
    ['inactive category', 'crop_ids'], ['wrong variety', 'crop_variety_id'], ['multiple variety', 'crop_variety_id'], ['inactive variety', 'crop_variety_id'],
]);

test('clears crops and variety when switching to all crops and preserves unrelated configuration', function () {
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->create(['is_active' => true]);
    $this->withToken(fieldLayoutToken());
    $id = $this->postJson('/api/admin/crop-field-layouts/step-1', fieldLayoutBasic(['crop_scope' => 'specific',
        'crop_ids' => [$crop->id], 'crop_variety_id' => $variety->id, 'layout_type' => 'individual_row']))->assertCreated()->json('data.id');
    $this->putJson('/api/admin/crop-field-layouts/'.$id.'/step-2', ['configuration' => ['row_spacing_m' => 0.3, 'plant_spacing_m' => 0.1]])->assertOk();

    $this->patchJson('/api/admin/crop-field-layouts/'.$id.'/step-1', ['crop_scope' => 'all'])
        ->assertOk()->assertJsonCount(0, 'data.crops')->assertJsonPath('data.crop_variety_id', null)
        ->assertJsonPath('data.configuration.row_spacing_m', 0.3)->assertJsonPath('data.wizard.completed_step', 2);
    $this->assertDatabaseCount('crop_field_layout_crops', 0);
});

test('requires restricted sowing methods to apply to every selected crop and rejects them for all-crop scope', function (string $scope, bool $includeOther) {
    $crop = Crop::factory()->published()->create();
    $other = Crop::factory()->published()->create();
    $method = SowingMethod::factory()->active()->create(['crop_scope' => 'specific']);
    $method->crops()->attach($crop);

    $this->withToken(fieldLayoutToken())->postJson('/api/admin/crop-field-layouts/step-1', fieldLayoutBasic([
        'crop_scope' => $scope, 'crop_ids' => $includeOther ? [$crop->id, $other->id] : [$crop->id], 'sowing_method_id' => $method->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('sowing_method_id');
    $this->assertDatabaseCount('crop_field_layouts', 0);
})->with([['all', false], ['specific', true]]);

test('accepts a single eligible variety and compatible method with related display names', function () {
    $crop = Crop::factory()->published('Fixture crop')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Fixture variety')->create(['is_active' => true]);
    $method = SowingMethod::factory()->active()->create(['crop_scope' => 'specific']);
    $method->crops()->attach($crop);

    $response = $this->withToken(fieldLayoutToken())->postJson('/api/admin/crop-field-layouts/step-1', fieldLayoutBasic([
        'crop_scope' => 'specific', 'crop_ids' => [$crop->id], 'crop_variety_id' => $variety->id, 'sowing_method_id' => $method->id,
    ]))->assertCreated()->assertJsonPath('data.crops.0.name', 'Fixture crop')->assertJsonPath('data.crop_variety.name', 'Fixture variety')
        ->assertJsonPath('data.sowing_method.id', $method->id);
    $this->assertDatabaseHas('crop_field_layout_crops', ['crop_field_layout_id' => $response->json('data.id'), 'crop_id' => $crop->id]);
});

test('persists supported configurations with normalized distances and whole counts', function (string $type, array $configuration) {
    $layout = CropFieldLayout::factory()->create(['layout_type' => $type]);

    $this->withToken(fieldLayoutToken())->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => (object) $configuration])
        ->assertOk()->assertJsonPath('data.wizard.completed_step', 2)->assertJsonPath('data.configuration_definition.preview_key', $type);
    $stored = $layout->fresh()->configuration;
    foreach ($configuration as $key => $value) {
        expect($stored[$key])->toEqual($value);
    }
})->with([
    ['random_distribution', []], ['continuous_row', ['row_spacing_m' => 0.225]],
    ['individual_row', ['row_spacing_m' => 0.3, 'plant_spacing_m' => 0.1]],
    ['square', ['planting_distance_m' => 6]], ['rectangular', ['row_spacing_m' => 4, 'plant_spacing_m' => 3]],
    ['raised_bed_multi_row', ['bed_width_m' => 1.2, 'path_width_m' => 0, 'rows_per_bed' => 2, 'row_spacing_m' => 0.5, 'plant_spacing_m' => 0.4]],
    ['raised_bed_multi_row', ['bed_width_m' => 1.2, 'path_width_m' => 0.4, 'rows_per_bed' => 2, 'row_spacing_m' => 0.5, 'planting_pattern' => 'continuous_row']],
    ['pole_based', ['support_row_spacing_m' => 3, 'support_spacing_m' => 3, 'plants_per_support' => 4]],
    ['trellis_based', ['row_spacing_m' => 2, 'plant_spacing_m' => 1.5]],
    ['rack_shelf', ['growing_unit_type' => 'bag', 'levels_per_rack' => 4, 'units_per_level' => 20]],
    ['hanging_bag', ['growing_unit_type' => 'bag', 'units_per_support' => 1]],
    ['floor_bed', ['bed_width_m' => 1.2, 'path_width_m' => 0.6]], ['channel_based', ['sites_per_channel' => 25]],
    ['grow_bag', ['plants_per_container' => 2]], ['container', ['plants_per_container' => 2]],
    ['vertical_tower', ['sites_per_tower' => 24]], ['tray', ['cells_per_tray' => 98]],
]);

test('returns 422 for invalid configuration without changing saved values or progress', function (string $type, mixed $configuration, string $error) {
    $layout = CropFieldLayout::factory()->create(['layout_type' => $type]);
    $before = $layout->fresh()->getRawOriginal();

    $this->withToken(fieldLayoutToken())->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => $configuration])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    expect($layout->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['random_distribution', ['unknown' => 3], 'configuration.unknown'], ['random_distribution', [], 'configuration'],
    ['random_distribution', 'invalid', 'configuration'], ['random_distribution', [1, 2], 'configuration'],
    ['continuous_row', (object) [], 'configuration.row_spacing_m'], ['continuous_row', ['row_spacing_m' => 0], 'configuration.row_spacing_m'],
    ['continuous_row', ['row_spacing_m' => -1], 'configuration.row_spacing_m'], ['continuous_row', ['row_spacing_m' => 'INF'], 'configuration.row_spacing_m'],
    ['continuous_row', ['row_spacing_m' => 1000001], 'configuration.row_spacing_m'],
    ['individual_row', ['row_spacing_m' => 0.3], 'configuration.plant_spacing_m'],
    ['square', ['planting_distance_m' => 6, 'row_spacing_m' => 6], 'configuration.row_spacing_m'],
    ['rack_shelf', ['growing_unit_type' => 'anything', 'levels_per_rack' => 4, 'units_per_level' => 20], 'configuration.growing_unit_type'],
    ['tray', ['cells_per_tray' => 2.5], 'configuration.cells_per_tray'], ['tray', ['cells_per_tray' => 0], 'configuration.cells_per_tray'],
    ['tray', ['cells_per_tray' => 65536], 'configuration.cells_per_tray'],
    ['raised_bed_multi_row', ['bed_width_m' => 1, 'path_width_m' => 0, 'rows_per_bed' => 4, 'row_spacing_m' => 0.5, 'plant_spacing_m' => 0.4], 'configuration.row_spacing_m'],
    ['raised_bed_multi_row', ['bed_width_m' => 1, 'path_width_m' => 0, 'rows_per_bed' => 2, 'row_spacing_m' => 0.5], 'configuration.plant_spacing_m'],
    ['raised_bed_multi_row', ['bed_width_m' => 1, 'path_width_m' => 0, 'rows_per_bed' => 2, 'row_spacing_m' => 0.5, 'plant_spacing_m' => 0.2, 'planting_pattern' => 'continuous_row'], 'configuration.plant_spacing_m'],
]);

test('rejects layout type unit version and overflowing reference notes on configuration saves', function (string $key, mixed $value) {
    $layout = CropFieldLayout::factory()->create();

    $this->withToken(fieldLayoutToken())->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', [
        'configuration' => (object) [], $key => $value,
    ])->assertUnprocessable()->assertJsonValidationErrors($key);
    expect($layout->fresh()->wizard_draft['completed_steps'])->toBe([1]);
})->with([['layout_type', 'square'], ['unit', 'cm'], ['configuration_version', 2], ['reference_note', str_repeat('a', 501)]]);

test('returns 409 for translations before configuration without saving rows', function () {
    $layout = CropFieldLayout::factory()->create();

    $this->withToken(fieldLayoutToken())->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => fieldLayoutTranslations()])->assertConflict();
    $this->assertDatabaseCount('crop_field_layout_translations', 0);
    expect($layout->fresh()->wizard_draft['completed_steps'])->toBe([1]);
});

test('returns 422 for invalid translations and rolls back every supplied locale', function (array $translations, string $error) {
    $layout = CropFieldLayout::factory()->create(['wizard_draft' => ['is_edit' => false, 'completed_steps' => [1, 2], 'requested_is_active' => false, 'revision' => 2]]);

    $this->withToken(fieldLayoutToken())->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => $translations])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    $this->assertDatabaseCount('crop_field_layout_translations', 0);
    expect($layout->fresh()->wizard_draft['completed_steps'])->toBe([1, 2]);
})->with([
    [['en' => ['title' => 'Valid', 'description' => 'Complete']], 'translations.hi.title'],
    [['en' => ['title' => 'Valid', 'description' => 'Complete'], 'zz' => ['title' => 'Unsupported']], 'translations'],
    [['en' => ['title' => 'Valid', 'description' => 'Complete'], 'hi' => ['title' => '   ', 'description' => 'Complete']], 'translations.hi.title'],
    [['en' => ['title' => 'Valid', 'description' => 'Complete'], 'hi' => ['title' => 'Valid']], 'translations.hi.description'],
    [['en' => ['title' => str_repeat('a', 201)]], 'translations.en.title'],
    [['en' => ['title' => 'Valid', 'description' => str_repeat('a', 16001)]], 'translations.en.description'],
    [['en' => ['title' => 'Valid', 'crop_field_layout_id' => 999]], 'translations.en'],
    [[['locale' => 'en', 'title' => 'First'], ['locale' => 'en', 'title' => 'Second']], 'translations'],
]);

test('supports future configured languages and preserves disabled saved locales and omitted fields', function () {
    config(['languages.supported.fr' => 'French']);
    $layout = CropFieldLayout::factory()->finalized()->create();
    $english = $layout->translations()->where('locale', 'en')->value('description');
    $this->withToken(fieldLayoutToken());

    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => ['fr' => ['title' => 'Disposition', 'description' => 'Description française.']]])
        ->assertOk()->assertJsonPath('data.draft.translations.fr.title', 'Disposition');
    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertOk();
    config(['languages.supported' => ['en' => 'English', 'hi' => 'Hindi']]);
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => ['en' => ['title' => 'Updated title']]])->assertOk();
    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertOk();
    $this->assertDatabaseHas('crop_field_layout_translations', ['crop_field_layout_id' => $layout->id, 'locale' => 'en', 'description' => $english]);
    $this->assertDatabaseHas('crop_field_layout_translations', ['crop_field_layout_id' => $layout->id, 'locale' => 'fr', 'title' => 'Disposition']);
    $this->assertDatabaseCount('crop_field_layout_translations', 3);
});

test('repeated translation saves update existing rows and preserve Unicode and omitted locales', function () {
    $layout = CropFieldLayout::factory()->create(['wizard_draft' => ['is_edit' => false, 'completed_steps' => [1, 2], 'requested_is_active' => false, 'revision' => 2]]);
    $this->withToken(fieldLayoutToken());
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => fieldLayoutTranslations()])->assertOk();

    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => ['en' => ['title' => 'Changed']]])
        ->assertOk()->assertJsonPath('data.translations.hi.title', 'परीक्षण कतार व्यवस्था');
    $this->assertDatabaseCount('crop_field_layout_translations', 2);
    $this->assertDatabaseHas('crop_field_layout_translations', ['crop_field_layout_id' => $layout->id, 'locale' => 'en', 'title' => 'Changed']);
});

test('keeps published values crops and translations unchanged until the edit is finalized', function () {
    $crop = Crop::factory()->published()->create();
    $layout = CropFieldLayout::factory()->active()->create();
    $oldTitle = $layout->translations()->where('locale', 'en')->value('title');
    $this->withToken(fieldLayoutToken());

    $this->patchJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-1', [
        'crop_scope' => 'specific', 'crop_ids' => [$crop->id], 'layout_type' => 'square', 'is_active' => false,
    ])->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.layout_type', 'random_distribution')
        ->assertJsonPath('data.draft.basic_details.layout_type', 'square')->assertJsonPath('data.wizard.completed_step', 1);
    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors('completed_step');
    $this->assertDatabaseCount('crop_field_layout_crops', 0);
    $this->assertDatabaseHas('crop_field_layouts', ['id' => $layout->id, 'is_active' => true, 'layout_type' => 'random_distribution']);
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => ['planting_distance_m' => 6]])->assertOk();
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => ['en' => ['title' => 'Pending edit']]])->assertOk();
    $this->assertDatabaseHas('crop_field_layout_translations', ['crop_field_layout_id' => $layout->id, 'locale' => 'en', 'title' => $oldTitle]);
    $this->getJson('/api/admin/crop-field-layouts/'.$layout->id)->assertOk()->assertJsonPath('data.draft.translations.en.title', 'Pending edit');
    $this->getJson('/api/admin/crop-field-layouts')->assertOk()->assertJsonPath('data.0.title', $oldTitle);
    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertOk()->assertJsonPath('data.title', 'Pending edit')
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.configuration.planting_distance_m', 6)->assertJsonPath('data.draft', null);
    $this->assertDatabaseHas('crop_field_layout_crops', ['crop_field_layout_id' => $layout->id, 'crop_id' => $crop->id]);
});

test('invalidates incompatible configuration and preserves translations when revisiting basic details', function () {
    $layout = CropFieldLayout::factory()->create();
    $this->withToken(fieldLayoutToken());
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => (object) []])->assertOk();
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-3', ['translations' => fieldLayoutTranslations()])->assertOk();

    $this->patchJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-1', ['layout_type' => 'continuous_row'])
        ->assertOk()->assertJsonPath('data.wizard.completed_step', 1)->assertJsonPath('data.translations.en.title', 'Test row layout');
    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertUnprocessable();
    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => ['row_spacing_m' => 0.225]])
        ->assertOk()->assertJsonPath('data.wizard.completed_step', 3);
    $this->assertDatabaseCount('crop_field_layouts', 1);
    $this->assertDatabaseCount('crop_field_layout_translations', 2);
});

test('revalidates master data at finalization without partially publishing edits', function () {
    $crop = Crop::factory()->published()->create();
    $layout = CropFieldLayout::factory()->active()->create();
    $this->withToken(fieldLayoutToken());
    $this->patchJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-1', ['crop_scope' => 'specific', 'crop_ids' => [$crop->id]])->assertOk();
    $crop->update(['is_active' => false]);

    $this->postJson('/api/admin/crop-field-layouts/'.$layout->id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors('crop_ids');
    $this->assertDatabaseHas('crop_field_layouts', ['id' => $layout->id, 'crop_scope' => 'all', 'is_active' => true]);
    $this->assertDatabaseCount('crop_field_layout_crops', 0);
    expect($layout->fresh()->wizard_draft)->not->toBeNull();
});

test('returns 409 for stale draft revisions without overwriting newer saves', function () {
    $layout = CropFieldLayout::factory()->create();
    $this->withToken(fieldLayoutToken());
    $this->patchJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-1', ['sort_order' => 10, 'draft_revision' => 1])->assertOk();
    $before = $layout->fresh()->getRawOriginal();

    $this->putJson('/api/admin/crop-field-layouts/'.$layout->id.'/step-2', ['configuration' => (object) [], 'draft_revision' => 1])->assertConflict();
    expect($layout->fresh()->getRawOriginal())->toBe($before);
});

test('groups crop applicability with other filters and paginates deterministically without duplicates', function () {
    $crop = Crop::factory()->published()->create();
    $otherCrop = Crop::factory()->published()->create();
    $method = SowingMethod::factory()->active()->create();
    $global = CropFieldLayout::factory()->finalized()->create(['sort_order' => 1, 'sowing_method_id' => $method->id, 'farming_type' => 'open_field']);
    $specific = CropFieldLayout::factory()->finalized()->create(['crop_scope' => 'specific', 'sort_order' => 1, 'sowing_method_id' => $method->id, 'farming_type' => 'open_field']);
    $specific->crops()->attach([$crop->id, $otherCrop->id]);
    $unrelated = CropFieldLayout::factory()->finalized()->create(['crop_scope' => 'specific', 'sowing_method_id' => $method->id, 'farming_type' => 'open_field']);
    $unrelated->crops()->attach($otherCrop);
    CropFieldLayout::factory()->finalized()->create(['sowing_method_id' => $method->id, 'farming_type' => 'indoor']);
    CropFieldLayout::factory()->finalized()->create(['farming_type' => 'open_field']);
    $this->withToken(fieldLayoutToken());
    $query = http_build_query(['crop_id' => $crop->id, 'sowing_method_id' => $method->id, 'layout_type' => 'random_distribution', 'farming_type' => 'open_field', 'per_page' => 1]);

    $first = $this->getJson('/api/admin/crop-field-layouts?'.$query)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $global->id)->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    expect($first->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id, 'farming_type=open_field');
    $this->getJson('/api/admin/crop-field-layouts?'.$query.'&page=2')->assertOk()->assertJsonPath('data.0.id', $specific->id);
    $this->getJson('/api/admin/crop-field-layouts?'.$query.'&page=3')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pagination.from', null);
});

test('searches titles and crop names with literal wildcard escaping and English fallback', function () {
    $layout = CropFieldLayout::factory()->finalized()->create();
    $layout->translations()->where('locale', 'en')->update(['title' => 'Fixture 100%_!']);
    $layout->translations()->where('locale', 'hi')->delete();
    $other = CropFieldLayout::factory()->finalized()->create();
    $other->translations()->where('locale', 'en')->update(['title' => 'Fixture 100 extra']);
    $crop = Crop::factory()->published('Unique crop fixture')->create();
    $other->crops()->attach($crop);
    $this->withToken(fieldLayoutToken());

    $this->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-field-layouts?keyword='.urlencode('100%_!'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Fixture 100%_!');
    $this->withHeader('X-Locale', 'en')->getJson('/api/admin/crop-field-layouts?keyword='.urlencode('Unique crop fixture'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $other->id);
    $this->getJson('/api/admin/crop-field-layouts?keyword='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data');
});

test('returns 422 for invalid listing filters and pagination', function (string $query, string $error) {
    $this->withToken(fieldLayoutToken())->getJson('/api/admin/crop-field-layouts?'.$query)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    ['page=0', 'page'], ['per_page=0', 'per_page'], ['per_page=101', 'per_page'], ['crop_id=99999', 'crop_id'],
    ['sowing_method_id=99999', 'sowing_method_id'], ['layout_type=custom', 'layout_type'], ['farming_type=unknown', 'farming_type'],
]);

test('searches the effective translation and uses English only when the requested translation is absent', function () {
    $layout = CropFieldLayout::factory()->finalized()->create();
    $layout->translations()->where('locale', 'en')->update(['title' => 'English only keyword']);
    $layout->translations()->where('locale', 'hi')->update(['title' => 'हिंदी शीर्षक']);
    $this->withToken(fieldLayoutToken())->withHeader('X-Locale', 'hi');

    $this->getJson('/api/admin/crop-field-layouts?keyword=English')->assertOk()->assertJsonCount(0, 'data');
    $layout->translations()->where('locale', 'hi')->delete();
    $this->getJson('/api/admin/crop-field-layouts?keyword=English')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'English only keyword');
});

test('lists related records without N plus one queries or lazy relationship access', function () {
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $method = SowingMethod::factory()->active()->create();
    $layout = CropFieldLayout::factory()->finalized()->create(['crop_variety_id' => $variety->id, 'sowing_method_id' => $method->id]);
    $layout->crops()->attach($crop);
    $this->withToken(fieldLayoutToken());
    Model::preventLazyLoading();
    DB::enableQueryLog();
    $this->getJson('/api/admin/crop-field-layouts')->assertOk();
    $one = count(DB::getQueryLog());
    CropFieldLayout::factory()->count(4)->finalized()->create(['crop_variety_id' => $variety->id, 'sowing_method_id' => $method->id])
        ->each(fn (CropFieldLayout $row) => $row->crops()->attach($crop));
    DB::flushQueryLog();

    $this->getJson('/api/admin/crop-field-layouts')->assertOk()->assertJsonCount(5, 'data');
    $many = count(DB::getQueryLog());
    DB::disableQueryLog();
    Model::preventLazyLoading(false);
    expect($many)->toBeLessThanOrEqual($one);
});

test('returns supported options and links to existing master APIs and marks undefined layouts deferred', function () {
    config(['languages.supported.fr' => 'French']);

    $response = $this->withToken(fieldLayoutToken())->getJson('/api/admin/crop-field-layouts/options')
        ->assertOk()->assertJsonCount(16, 'data.layout_types')->assertJsonCount(3, 'data.supported_locales')
        ->assertJsonPath('data.default_locale', 'en')->assertJsonPath('data.layout_types.0.value', 'random_distribution')
        ->assertJsonPath('data.layout_types.0.fields', [])->assertJsonPath('data.layout_types.2.fields.0.storage_unit', 'm');
    expect($response->json('data.master_data_endpoints.crop_varieties'))->toEndWith('/api/admin/crop-varieties');
    expect($response->json('data.deferred_layout_types'))->toContain('custom', 'floating_raft');
});

test('deletes an unreferenced layout and owned translations and crop associations', function () {
    $crop = Crop::factory()->published()->create();
    $layout = CropFieldLayout::factory()->finalized()->create(['crop_scope' => 'specific']);
    $layout->crops()->attach($crop);

    $this->withToken(fieldLayoutToken())->deleteJson('/api/admin/crop-field-layouts/'.$layout->id)->assertOk()
        ->assertExactJson(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Field layout deleted successfully.']);
    $this->assertModelMissing($layout);
    $this->assertDatabaseCount('crop_field_layout_translations', 0);
    $this->assertDatabaseCount('crop_field_layout_crops', 0);
    $this->assertModelExists($crop);
});

test('returns 409 when another implemented feature references the layout', function () {
    $layout = CropFieldLayout::factory()->finalized()->create();
    Schema::create('field_layout_test_references', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('crop_field_layout_id')->constrained()->cascadeOnDelete();
    });
    try {
        DB::table('field_layout_test_references')->insert(['crop_field_layout_id' => $layout->id]);

        $this->withToken(fieldLayoutToken())->deleteJson('/api/admin/crop-field-layouts/'.$layout->id)->assertConflict()
            ->assertJsonPath('message', 'Referenced field layouts cannot be deleted. Deactivate the layout instead.');
        $this->assertModelExists($layout);
        $this->assertDatabaseCount('field_layout_test_references', 1);
    } finally {
        Schema::dropIfExists('field_layout_test_references');
    }
});

test('returns 409 when deleting a sowing method referenced by a field layout', function () {
    $method = SowingMethod::factory()->active()->create();
    $layout = CropFieldLayout::factory()->finalized()->create(['sowing_method_id' => $method->id]);

    $this->withToken(fieldLayoutToken())->deleteJson('/api/admin/sowing-methods/'.$method->id)->assertConflict()
        ->assertJsonPath('message', 'Referenced sowing methods cannot be deleted. Deactivate the method instead.');
    $this->assertModelExists($method);
    $this->assertModelExists($layout);
});

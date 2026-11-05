<?php

use App\Http\Resources\Admin\CropCategoryResource;
use App\Http\Resources\Admin\CropCategoryTranslationResource;
use App\Models\AdminUser;
use App\Models\CropCategory;
use App\Models\CropCategoryTranslation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

test('activation requires nonempty English even when another language exists', function (string $englishState) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    if ($englishState !== 'missing') {
        CropCategoryTranslation::factory()->for($category)->create(['locale' => 'en', 'name' => ' ']);
    }
    CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id.'/status', ['is_active' => true])
        ->assertConflict()->assertJsonPath('message', 'An English translation is required before activating the crop category.')
        ->assertJsonPath('data', null);

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => false]);
})->with(['missing', 'empty']);

test('authorized managers activate categories with English names and can deactivate them', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-categories/'.$category->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => true]);

    $this->withToken($token)->patchJson('/api/admin/crop-categories/'.$category->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => false]);
});

test('deactivation remains available without English content', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create(['is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id.'/status', ['is_active' => false])->assertOk();

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => false]);
});

test('status updates validate boolean input', function (array $payload) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => false]);
})->with(['missing' => [[]], 'invalid' => [['is_active' => 'published']]]);

test('ordinary metadata edits cannot activate a category', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => false]);
});

test('localized administration resolves requested text and English fallback before searching and sorting', function () {
    $admin = AdminUser::factory()->create();
    $first = CropCategory::factory()->published('Zulu')->create();
    CropCategoryTranslation::factory()->for($first)->create(['locale' => 'hi', 'name' => 'Alpha']);
    $second = CropCategory::factory()->published('Beta')->create();
    $third = CropCategory::factory()->published('Delta')->create();
    CropCategoryTranslation::factory()->for($third)->create(['locale' => 'hi', 'name' => 'Charlie']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/crop-categories?locale=hi')
        ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.name', 'Alpha')
        ->assertJsonPath('data.1.id', $second->id)->assertJsonPath('data.1.name', 'Beta')
        ->assertJsonPath('data.2.id', $third->id)->assertJsonPath('data.2.name', 'Charlie')
        ->assertJsonPath('pagination.total', 3);
    $this->withToken($token)->getJson('/api/admin/crop-categories?locale=hi&keyword=Alpha')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
    $this->withToken($token)->getJson('/api/admin/crop-categories?locale=hi&keyword=Zulu')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
});

test('publication queries exclude inactive categories and categories missing nonempty English', function (string $state) {
    $eligible = CropCategory::factory()->published('Published')->create();
    $category = CropCategory::factory()->create(['is_active' => $state !== 'inactive']);
    if ($state !== 'missing') {
        CropCategoryTranslation::factory()->for($category)->create([
            'locale' => 'en', 'name' => $state === 'empty' ? ' ' : 'Unpublished',
        ]);
    }
    CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);

    $published = CropCategory::query()->published('hi')->get();

    expect($published->modelKeys())->toBe([$eligible->id]);
    expect($published->sole()->localized_name)->toBe('Published');
})->with(['inactive', 'missing', 'empty']);

test('publication loads the requested locale with English fallback and excludes unrelated locales', function (bool $withHindi, string $expected) {
    config(['languages.supported.fr' => 'French']);
    $category = CropCategory::factory()->published('Cereals')->create();
    if ($withHindi) {
        CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);
    }
    CropCategoryTranslation::factory()->for($category)->create(['locale' => 'fr', 'name' => 'Céréales']);

    $published = CropCategory::query()->published('hi')->sole();

    expect($published->localized_name)->toBe($expected);
    expect($published->translatedName('hi'))->toBe($expected);
    expect($published->translations->contains('locale', 'fr'))->toBeFalse();
})->with(['requested translation' => [true, 'अनाज'], 'English fallback' => [false, 'Cereals']]);

test('unsupported publication locales fall back to English', function () {
    CropCategory::factory()->published('Cereals')->create();

    expect(CropCategory::query()->published('xx')->sole()->localized_name)->toBe('Cereals');
});

test('publication resources expose resolved names without full translation maps or extra queries', function () {
    $category = CropCategory::factory()->published()->create();
    $hindi = CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);
    $published = CropCategory::query()->published('hi')->sole();
    $request = Request::create('/api/future-crop-categories', 'GET');
    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = (new CropCategoryResource($published))->resolve($request);
    $translation = (new CropCategoryTranslationResource($hindi))->resolve($request);

    expect($result['name'])->toBe('अनाज');
    expect($result)->not->toHaveKey('translations');
    expect($translation)->toBe(['locale' => 'hi', 'name' => 'अनाज', 'description' => null]);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('English edits to an active category remain published immediately', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, ['translations' => ['en' => ['name' => 'Grain crops']]])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.translations.en.name', 'Grain crops');

    expect(CropCategory::query()->published()->sole()->localized_name)->toBe('Grain crops');
});

test('explicit null clears a translated description without changing activation', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();
    $category->translations()->where('locale', 'en')->firstOrFail()->update(['description' => 'Previous description']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, ['translations' => ['en' => ['name' => 'Cereals', 'description' => null]]])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.translations.en.description', null);

    $this->assertDatabaseHas('crop_category_translations', ['crop_category_id' => $category->id, 'locale' => 'en', 'description' => null]);
});

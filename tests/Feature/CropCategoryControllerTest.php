<?php

use App\Models\AdminUser;
use App\Models\CropCategory;
use App\Models\CropCategoryTranslation;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('crop category routes require admin bearer authentication', function (string $method, string $suffix) {
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();

    $this->json($method, '/api/admin/crop-categories'.str_replace('{id}', $category->id, $suffix))
        ->assertUnauthorized()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
        ]);

    $this->assertModelExists($category);
})->with([
    'index' => ['GET', ''], 'store' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'destroy' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('frontend tokens cannot access crop category administration', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/crop-categories')->assertUnauthorized();
});

test('inactive admins and tokens missing admin ability receive 403', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/crop-categories')->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'missing ability' => [true, ['frontend']]]);

test('crop category management permits active admin identities', function () {
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-crop-categories'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->superUser()->make())->allows('manage-crop-categories'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->inactive()->make())->allows('manage-crop-categories'))->toBeFalse();
    expect(Gate::forUser(User::factory()->make())->allows('manage-crop-categories'))->toBeFalse();
});

test('all crop category endpoints enforce the management gate', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    Gate::define('manage-crop-categories', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crop-categories'.str_replace('{id}', $category->id, $suffix))->assertForbidden();

    $this->assertModelExists($category);
    $this->assertDatabaseCount('crop_category_translations', 1);
})->with([
    'index' => ['GET', ''], 'store' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'destroy' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('admins create inactive crop categories with all supplied translations', function (array $translations) {
    $admin = AdminUser::factory()->create();
    $this->travelTo('2026-11-04 09:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', [
            'slug' => 'cereals', 'is_active' => true, 'translations' => $translations, 'id' => 999,
        ]);

    $response->assertCreated()->assertJsonPath('data.slug', 'cereals')->assertJsonPath('data.name', 'Cereals')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.added_at', '4 Nov 2026 09:00 AM')
        ->assertJsonPath('data.created_at', '2026-11-04T09:00:00.123456Z')->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data.translations.en')))->toBe(['locale', 'name', 'description']);
    $this->assertDatabaseHas('crop_categories', ['id' => $response->json('data.id'), 'slug' => 'cereals', 'is_active' => false]);
    $this->assertDatabaseCount('crop_category_translations', count($translations));
    foreach ($translations as $locale => $values) {
        $this->assertDatabaseHas('crop_category_translations', [
            'crop_category_id' => $response->json('data.id'), 'locale' => $locale, 'name' => $values['name'],
        ]);
    }
})->with([
    'English' => [['en' => ['name' => 'Cereals']]],
    'English and Hindi' => [[
        'en' => ['name' => 'Cereals', 'description' => 'Edible grains.'],
        'hi' => ['name' => 'अनाज', 'description' => 'अनाज वाली फसलें।'],
    ]],
]);

test('slugs are generated from English names when omitted or null', function (array $slugInput) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', [
            ...$slugInput, 'translations' => ['en' => ['name' => 'Oil Seeds & Pulses']],
        ])->assertCreated()->assertJsonPath('data.slug', 'oil-seeds-pulses');

    $this->assertDatabaseHas('crop_categories', ['slug' => 'oil-seeds-pulses']);
})->with(['omitted' => [[]], 'null' => [['slug' => null]]]);

test('invalid category creation fails before saving any translations', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', ['slug' => 'cereals', ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertDatabaseCount('crop_categories', 0);
    $this->assertDatabaseCount('crop_category_translations', 0);
})->with([
    'missing English' => [['translations' => ['hi' => ['name' => 'अनाज']]], 'translations.en.name'],
    'empty English' => [['translations' => ['en' => ['name' => ' ']]], 'translations.en.name'],
    'missing translations' => [[], 'translations'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'Cereals'], 'xx' => ['name' => 'Other']]], 'translations'],
    'missing supplied name' => [['translations' => ['en' => ['name' => 'Cereals'], 'hi' => ['description' => 'Missing name']]], 'translations.hi.name'],
    'name too long' => [['translations' => ['en' => ['name' => str_repeat('x', 256)]]], 'translations.en.name'],
    'invalid description' => [['translations' => ['en' => ['name' => 'Cereals', 'description' => ['invalid']]]], 'translations.en.description'],
    'arbitrary translation field' => [['translations' => ['en' => ['name' => 'Cereals', 'crop_category_id' => 999]]], 'translations.en'],
    'invalid slug' => [['slug' => 'Invalid Slug', 'translations' => ['en' => ['name' => 'Cereals']]], 'slug'],
    'long slug' => [['slug' => str_repeat('x', 256), 'translations' => ['en' => ['name' => 'Cereals']]], 'slug'],
]);

test('missing English name uses the required client error path and message', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', ['slug' => 'cereals', 'translations' => ['en' => ['description' => 'No name']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['translations.en.name' => 'The English category name is required.']);
});

test('configured languages beyond English and Hindi are accepted', function () {
    config(['languages.supported.fr' => 'French']);
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', [
            'translations' => ['en' => ['name' => 'Cereals'], 'fr' => ['name' => 'Céréales']],
        ])->assertCreated()->assertJsonPath('data.translations.fr.name', 'Céréales');

    $this->assertDatabaseHas('crop_category_translations', ['locale' => 'fr', 'name' => 'Céréales']);
});

test('duplicate explicit and generated slugs return 422', function (array $slugInput) {
    $admin = AdminUser::factory()->create();
    CropCategory::factory()->withEnglishName('Cereals')->create(['slug' => 'cereals']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', [...$slugInput, 'translations' => ['en' => ['name' => 'Cereals']]])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');

    $this->assertDatabaseCount('crop_categories', 1);
})->with(['explicit' => [['slug' => 'cereals']], 'generated' => [[]]]);

test('database constraints enforce category slug and translation locale uniqueness', function (string $kind) {
    $category = CropCategory::factory()->withEnglishName('Cereals')->create(['slug' => 'cereals']);

    expect(fn () => match ($kind) {
        'slug' => CropCategory::factory()->create(['slug' => 'cereals']),
        'locale' => CropCategoryTranslation::factory()->for($category)->create(['locale' => 'en']),
    })->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('crop_categories', 1);
    $this->assertDatabaseCount('crop_category_translations', 1);
})->with(['slug', 'locale']);

test('translation updates preserve omitted fields locales and activation', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create(['slug' => 'cereals']);
    $english = $category->translations()->where('locale', 'en')->firstOrFail();
    $hindi = CropCategoryTranslation::factory()->for($category)->create([
        'locale' => 'hi', 'name' => 'अनाज', 'description' => 'Preserved description.',
    ]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, [
            'translations' => ['hi' => ['name' => 'अनाज की फसलें']], 'is_active' => false,
        ])->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.translations.hi.name', 'अनाज की फसलें')
        ->assertJsonPath('data.translations.hi.description', 'Preserved description.')
        ->assertJsonPath('data.translations.en.name', 'Cereals')->assertJsonPath('data.slug', 'cereals');

    $this->assertDatabaseHas('crop_category_translations', ['id' => $english->id, 'name' => 'Cereals']);
    $this->assertDatabaseHas('crop_category_translations', ['id' => $hindi->id, 'name' => 'अनाज की फसलें', 'description' => 'Preserved description.']);
    $this->assertDatabaseCount('crop_category_translations', 2);
});

test('unchanged translations retain content timestamps and activation', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();
    $translation = $category->translations()->sole();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, ['translations' => ['en' => ['name' => 'Cereals']]])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.translations.en.name', 'Cereals');

    expect($translation->fresh()->updated_at->toISOString())->toBe($translation->updated_at->toISOString());
    $this->assertDatabaseHas('crop_category_translations', ['id' => $translation->id, 'name' => 'Cereals']);
});

test('English edits preserve activation slug and other translations', function (array $changes) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create(['slug' => 'stable-cereals']);
    $hindi = CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, ['translations' => ['en' => ['name' => 'Cereals', ...$changes]]])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.slug', 'stable-cereals')
        ->assertJsonPath('data.translations.hi.name', 'अनाज');

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => true, 'slug' => 'stable-cereals']);
    $this->assertDatabaseHas('crop_category_translations', ['crop_category_id' => $category->id, 'locale' => 'en', ...$changes]);
    $this->assertDatabaseHas('crop_category_translations', ['id' => $hindi->id, 'name' => 'अनाज']);
})->with(['name' => [['name' => 'Grain crops']], 'description' => [['description' => 'New classification text']]]);

test('explicit slug changes are validated and preserve translations and activation', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create(['slug' => 'cereals']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-categories/'.$category->id, ['slug' => 'grain-crops'])
        ->assertOk()->assertJsonPath('data.slug', 'grain-crops')->assertJsonPath('data.translations.en.name', 'Cereals');
    $this->withToken($token)->patchJson('/api/admin/crop-categories/'.$category->id, ['slug' => 'grain-crops'])->assertOk();

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'slug' => 'grain-crops', 'is_active' => true]);
});

test('invalid partial updates preserve stored category and translations', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create(['slug' => 'cereals']);
    CropCategory::factory()->create(['slug' => 'other-category']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-categories/'.$category->id, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_categories', ['id' => $category->id, 'is_active' => true, 'slug' => 'cereals']);
    $this->assertDatabaseHas('crop_category_translations', ['crop_category_id' => $category->id, 'locale' => 'en', 'name' => 'Cereals']);
})->with([
    'empty translations' => [['translations' => []], 'translations'],
    'delete English' => [['translations' => ['en' => null]], 'translations.en'],
    'description without name' => [['translations' => ['en' => ['description' => 'Changed']]], 'translations.en.name'],
    'unknown nested field' => [['translations' => ['en' => ['name' => 'Cereals', 'unknown' => true]]], 'translations.en'],
    'other category slug' => [['slug' => 'other-category'], 'slug'],
    'null slug' => [['slug' => null], 'slug'],
    'invalid slug' => [['slug' => '../invalid'], 'slug'],
    'unsupported locale' => [['translations' => ['xx' => ['name' => 'Other']]], 'translations'],
]);

test('failed multi-language persistence rolls back the master and every translation', function () {
    $admin = AdminUser::factory()->create();
    CropCategoryTranslation::creating(function (CropCategoryTranslation $translation): void {
        if ($translation->locale === 'hi') {
            throw new RuntimeException('Simulated translation persistence failure.');
        }
    });
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-categories', ['translations' => ['en' => ['name' => 'Cereals'], 'hi' => ['name' => 'अनाज']]])
        ->assertInternalServerError()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'An unexpected error occurred.',
        ]);

    $this->assertDatabaseCount('crop_categories', 0);
    $this->assertDatabaseCount('crop_category_translations', 0);
    Exceptions::assertReported(RuntimeException::class);
});

test('admin detail includes every translation without lazy loading', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);
    Model::preventLazyLoading();

    try {
        $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->getJson('/api/admin/crop-categories/'.$category->id)->assertOk()
            ->assertJsonPath('data.name', 'Cereals')->assertJsonPath('data.translations.en.name', 'Cereals')
            ->assertJsonPath('data.translations.hi.name', 'अनाज');

        expect(array_keys($response->json('data.translations.hi')))->toBe(['locale', 'name', 'description']);
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('admin categories sort by English name with stable IDs by default', function () {
    $admin = AdminUser::factory()->create();
    CropCategory::factory()->withEnglishName('Vegetables')->create();
    $first = CropCategory::factory()->withEnglishName('Cereals')->create();
    $second = CropCategory::factory()->withEnglishName('Cereals')->create();
    CropCategoryTranslation::factory()->for($first)->create(['locale' => 'hi', 'name' => 'अनाज']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-categories')->assertOk()
        ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $second->id)
        ->assertJsonPath('data.0.name', 'Cereals')->assertJsonPath('pagination.total', 3);
});

test('category localization follows the active application locale instead of a query parameter', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    CropCategoryTranslation::factory()->for($category)->create(['locale' => 'hi', 'name' => 'अनाज']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-categories?keyword=अनाज&locale=en')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $category->id)
        ->assertJsonPath('data.0.name', 'अनाज');

    $this->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-categories/'.$category->id.'?locale=en')
        ->assertOk()
        ->assertJsonPath('data.name', 'अनाज');
});

test('admin search matches literal English names and slugs', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $match = CropCategory::factory()->withEnglishName('Grains 50%_!')->create(['slug' => 'grain-crops']);
    CropCategory::factory()->withEnglishName('Grains 500abc')->create(['slug' => 'vegetables']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-categories?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('pagination.total', 1);
})->with(['English name' => ['50%_!'], 'slug' => ['grain-crops']]);

test('filters and pagination operate on categories without duplicate translation joins', function () {
    $admin = AdminUser::factory()->create();
    $first = CropCategory::factory()->withEnglishName('Cereals')->create();
    CropCategoryTranslation::factory()->for($first)->create(['locale' => 'hi', 'name' => 'अनाज']);
    CropCategory::factory()->withEnglishName('Vegetables')->create();
    CropCategory::factory()->published('Pulses')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-categories?is_active=0&per_page=1');

    $response->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2)
        ->assertJsonPath('pagination.per_page', 1)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 1);
    expect($response->json('pagination.next_page_url'))->toContain('is_active=0', 'per_page=1');
    expect($response->json('pagination.next_page_url'))->not->toContain('locale=');
});

test('invalid list filters return 422', function (string $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-categories?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page' => ['page=0', 'page'], 'per page' => ['per_page=101', 'per_page'],
    'boolean' => ['is_active=invalid', 'is_active'], 'array keyword' => ['keyword[]=injection', 'keyword'],
]);

test('empty category pages use the standard pagination contract', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crop-categories')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null)->assertJsonPath('pagination.per_page', 15);
});

test('unreferenced inactive categories delete with their translations', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-categories/'.$category->id)->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($category);
    $this->assertDatabaseCount('crop_category_translations', 0);
});

test('active categories must be deactivated before deletion', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-categories/'.$category->id)->assertConflict();

    $this->assertModelExists($category);
    $this->assertDatabaseCount('crop_category_translations', 1);
});

test('existing historical references prevent deletion and preserve history and translations', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    Schema::create('retained_crop_category_records', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('crop_category_id')->constrained('crop_categories')->restrictOnDelete();
        $table->string('historical_name');
    });
    try {
        DB::table('retained_crop_category_records')->insert(['crop_category_id' => $category->id, 'historical_name' => 'Original crop history']);

        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->deleteJson('/api/admin/crop-categories/'.$category->id)->assertConflict()->assertJsonPath('data', null);

        $this->assertModelExists($category);
        $this->assertDatabaseCount('crop_category_translations', 1);
        $this->assertDatabaseHas('retained_crop_category_records', ['crop_category_id' => $category->id, 'historical_name' => 'Original crop history']);
    } finally {
        Schema::dropIfExists('retained_crop_category_records');
    }
});

test('missing crop categories return the standard 404 envelope', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-categories/999')->assertNotFound()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
        ]);
});

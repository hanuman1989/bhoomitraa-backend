<?php

use App\Http\Resources\Admin\ActivityCategoryResource;
use App\Models\ActivityCategory;
use App\Models\ActivityCategoryTranslation;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function activityCategoryPayload(array $overrides = []): array
{
    return array_replace([
        'slug' => 'irrigation', 'icon' => 'LeafIcon',
        'translations' => [
            'en' => ['name' => 'Irrigation', 'description' => 'Water management activities.'],
            'hi' => ['name' => 'सिंचाई', 'description' => 'सिंचाई प्रबंधन की गतिविधियाँ।'],
        ],
    ], $overrides);
}

test('activity category endpoints require admin authentication', function (string $method, string $suffix) {
    $category = ActivityCategory::factory()->withNames()->create();

    $this->json($method, '/api/admin/activity-categories'.str_replace('{id}', $category->id, $suffix))
        ->assertUnauthorized()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
        ]);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('activity category access rejects frontend tokens inactive admins and tokens without admin ability', function (string $identity, bool $active, array $abilities, int $expectedStatus) {
    $user = $identity === 'frontend' ? User::factory()->create() : AdminUser::factory()->create(['status' => $active]);

    $this->withToken($user->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/activity-categories')->assertStatus($expectedStatus);
})->with([
    'frontend identity' => ['frontend', true, ['*'], 401],
    'inactive admin' => ['admin', false, ['admin'], 403],
    'missing ability' => ['admin', true, ['frontend'], 403],
]);

test('activity category gates distinguish management from system management', function () {
    $admin = AdminUser::factory()->make();
    $superUser = AdminUser::factory()->superUser()->make();
    $inactive = AdminUser::factory()->superUser()->inactive()->make();
    $frontend = User::factory()->make();

    expect(Gate::forUser($admin)->allows('manage-activity-categories'))->toBeTrue();
    expect(Gate::forUser($admin)->allows('manage-system-activity-categories'))->toBeFalse();
    expect(Gate::forUser($superUser)->allows('manage-system-activity-categories'))->toBeTrue();
    expect(Gate::forUser($inactive)->allows('manage-activity-categories'))->toBeFalse();
    expect(Gate::forUser($inactive)->allows('manage-system-activity-categories'))->toBeFalse();
    expect(Gate::forUser($frontend)->allows('manage-activity-categories'))->toBeFalse();
    expect(Gate::forUser($frontend)->allows('manage-system-activity-categories'))->toBeFalse();
});

test('management denial protects every activity category endpoint', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $category = ActivityCategory::factory()->withNames()->create();
    Gate::define('manage-activity-categories', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/activity-categories'.str_replace('{id}', $category->id, $suffix), activityCategoryPayload())
        ->assertForbidden();

    $this->assertModelExists($category);
    $this->assertDatabaseCount('activity_categories', 1);
    $this->assertDatabaseCount('activity_category_translations', 2);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('admins create inactive custom categories with all supplied configured translations', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $this->travelTo('2026-11-05 10:00:00.123456');
    $admin = AdminUser::factory()->create();
    $payload = activityCategoryPayload();
    $payload['translations']['gu'] = ['name' => 'સિંચાઈ', 'description' => ''];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/activity-categories', $payload)->assertCreated()
        ->assertJsonPath('data.slug', 'irrigation')->assertJsonPath('data.icon', 'LeafIcon')
        ->assertJsonPath('data.is_system', false)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.name', 'Irrigation')->assertJsonPath('data.translations.en.locale', 'en')
        ->assertJsonPath('data.translations.hi.name', 'सिंचाई')->assertJsonPath('data.translations.gu.name', 'સિંચાઈ')
        ->assertJsonPath('data.translations.gu.description', null)
        ->assertJsonPath('data.created_at', '2026-11-05T10:00:00.123456Z')
        ->assertJsonPath('data.added_at', '5 Nov 2026 10:00 AM')->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('activity_categories', ['id' => $response->json('data.id'), 'slug' => 'irrigation', 'is_system' => false, 'is_active' => false]);
    $this->assertDatabaseCount('activity_category_translations', 3);
    $this->assertDatabaseHas('activity_category_translations', ['activity_category_id' => $response->json('data.id'), 'locale' => 'gu', 'name' => 'સિંચાઈ']);
    $translation = ActivityCategoryTranslation::where('locale', 'hi')->firstOrFail();
    expect($translation->activityCategory->id)->toBe($response->json('data.id'));
});

test('nullable icons and boundary length names and slugs are accepted', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/activity-categories', activityCategoryPayload([
            'slug' => str_repeat('a', 180), 'icon' => null,
            'translations' => ['en' => ['name' => str_repeat('A', 150)], 'hi' => ['name' => 'सिंचाई']],
        ]))->assertCreated()->assertJsonPath('data.icon', null);

    $this->assertDatabaseHas('activity_categories', ['id' => $response->json('data.id'), 'icon' => null, 'slug' => str_repeat('a', 180)]);
});

test('missing activity category metadata returns nested validation messages and makes no writes', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/activity-categories', [])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'slug' => 'The slug field is required.',
            'translations.en.name' => 'The English activity category name is required.',
            'translations.hi.name' => 'The Hindi activity category name is required.',
        ])->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('activity_categories', 0);
    $this->assertDatabaseCount('activity_category_translations', 0);
});

test('invalid activity category payloads return 422 without persistence', function (array $overrides, string $field) {
    $admin = AdminUser::factory()->superUser()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/activity-categories', activityCategoryPayload($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('activity_categories', 0);
    $this->assertDatabaseCount('activity_category_translations', 0);
})->with([
    'uppercase slug' => [['slug' => 'Irrigation'], 'slug'],
    'slug separator' => [['slug' => 'crop--monitoring'], 'slug'],
    'slug underscores' => [['slug' => 'crop_monitoring'], 'slug'],
    'trailing hyphen' => [['slug' => 'crop-'], 'slug'],
    'slug newline' => [['slug' => "crop\nmonitoring"], 'slug'],
    'long slug' => [['slug' => str_repeat('a', 181)], 'slug'],
    'null slug' => [['slug' => null], 'slug'],
    'unknown icon' => [['icon' => 'not-an-icon'], 'icon'],
    'unsupported Lucide identifier' => [['icon' => 'droplets'], 'icon'],
    'executable SVG' => [['icon' => '<svg onload=alert(1)>'], 'icon'],
    'JavaScript icon' => [['icon' => 'javascript:alert(1)'], 'icon'],
    'long icon' => [['icon' => str_repeat('a', 101)], 'icon'],
    'invalid system flag' => [['is_system' => 'yes'], 'is_system'],
    'active bypass' => [['is_active' => true], 'is_active'],
    'injected ID' => [['id' => 42], 'id'],
    'injected timestamp' => [['created_at' => '2000-01-01'], 'created_at'],
    'request locale' => [['locale' => 'hi'], 'locale'],
    'null translations' => [['translations' => null], 'translations'],
    'empty translations' => [['translations' => []], 'translations'],
    'missing English' => [['translations' => ['hi' => ['name' => 'सिंचाई']]], 'translations.en.name'],
    'missing Hindi' => [['translations' => ['en' => ['name' => 'Irrigation']]], 'translations.hi.name'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'Irrigation'], 'hi' => ['name' => 'सिंचाई'], 'fr' => ['name' => 'French']]], 'translations'],
    'null English name' => [['translations' => ['en' => ['name' => null], 'hi' => ['name' => 'सिंचाई']]], 'translations.en.name'],
    'blank Hindi name' => [['translations' => ['en' => ['name' => 'Irrigation'], 'hi' => ['name' => ' ']]], 'translations.hi.name'],
    'long name' => [['translations' => ['en' => ['name' => str_repeat('a', 151)], 'hi' => ['name' => 'सिंचाई']]], 'translations.en.name'],
    'invalid description' => [['translations' => ['en' => ['name' => 'Irrigation', 'description' => []], 'hi' => ['name' => 'सिंचाई']]], 'translations.en.description'],
    'long description' => [['translations' => ['en' => ['name' => 'Irrigation', 'description' => str_repeat('a', 65536)], 'hi' => ['name' => 'सिंचाई']]], 'translations.en.description'],
    'nested foreign key' => [['translations' => ['en' => ['name' => 'Irrigation', 'activity_category_id' => 999], 'hi' => ['name' => 'सिंचाई']]], 'translations.en'],
    'nested timestamp' => [['translations' => ['en' => ['name' => 'Irrigation'], 'hi' => ['name' => 'सिंचाई', 'updated_at' => '2000-01-01']]], 'translations.hi'],
]);

test('duplicate slugs return 422 on creation and update while the current slug is accepted', function () {
    $admin = AdminUser::factory()->create();
    $first = ActivityCategory::factory()->withNames()->create(['slug' => 'irrigation']);
    $second = ActivityCategory::factory()->withNames()->create(['slug' => 'monitoring']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson('/api/admin/activity-categories', activityCategoryPayload())
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson('/api/admin/activity-categories/'.$second->id, ['slug' => 'irrigation'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson('/api/admin/activity-categories/'.$first->id, ['slug' => 'irrigation'])->assertOk();

    $this->assertDatabaseCount('activity_categories', 2);
    $this->assertDatabaseHas('activity_categories', ['id' => $second->id, 'slug' => 'monitoring']);
});

test('only authorized super users may create system categories', function (bool $superUser) {
    $admin = AdminUser::factory()->create(['is_super_user' => $superUser]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/activity-categories', activityCategoryPayload(['is_system' => true]));

    if ($superUser) {
        $response->assertCreated()->assertJsonPath('data.is_system', true)->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('activity_categories', ['slug' => 'irrigation', 'is_system' => true, 'is_active' => false]);
    } else {
        $response->assertForbidden();
        $this->assertDatabaseCount('activity_categories', 0);
        $this->assertDatabaseCount('activity_category_translations', 0);
    }
})->with(['ordinary admin' => false, 'super user' => true]);

test('partial activity category updates preserve omitted translations fields and status', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->published()->create(['slug' => 'irrigation', 'icon' => 'LeafIcon']);
    $category->translations()->where('locale', 'en')->update(['description' => 'Keep English description']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/activity-categories/'.$category->id, [
        'slug' => 'updated-irrigation', 'icon' => 'SeedIcon',
        'translations' => [
            'en' => ['name' => 'Updated irrigation'], 'hi' => ['description' => 'नया विवरण'], 'gu' => ['name' => 'સિંચાઈ'],
        ],
    ])->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.translations.en.description', 'Keep English description')
        ->assertJsonPath('data.translations.hi.name', 'सिंचाई')->assertJsonPath('data.translations.gu.name', 'સિંચાઈ');
    $this->patchJson('/api/admin/activity-categories/'.$category->id, ['translations' => ['en' => ['description' => null]]])
        ->assertOk()->assertJsonPath('data.translations.en.name', 'Updated irrigation')
        ->assertJsonPath('data.translations.en.description', null)->assertJsonPath('data.translations.hi.description', 'नया विवरण')
        ->assertJsonPath('data.translations.gu.name', 'સિંચાઈ');

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'slug' => 'updated-irrigation', 'icon' => 'SeedIcon', 'is_active' => true]);
    $this->assertDatabaseCount('activity_category_translations', 3);
});

test('invalid metadata updates return 422 and preserve the existing category', function (array $changes, string $field) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->superUser()->create();
    $category = ActivityCategory::factory()->withNames()->create(['slug' => 'irrigation']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/activity-categories/'.$category->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'slug' => 'irrigation', 'is_active' => false, 'is_system' => false]);
    $this->assertDatabaseCount('activity_category_translations', 2);
})->with([
    'system elevation' => [['is_system' => true], 'is_system'],
    'system flag even unchanged' => [['is_system' => false], 'is_system'],
    'status bypass' => [['is_active' => true], 'is_active'],
    'arbitrary field' => [['review_status' => 'approved'], 'review_status'],
    'empty required name' => [['translations' => ['hi' => ['name' => null]]], 'translations.hi.name'],
    'new locale without name' => [['translations' => ['gu' => ['description' => 'Description only']]], 'translations.gu.name'],
    'empty optional translation' => [['translations' => ['gu' => []]], 'translations.gu'],
    'unsupported translation field' => [['translations' => ['hi' => ['locale' => 'gu']]], 'translations.hi'],
]);

test('ordinary admins can read system categories but cannot edit them or toggle their status', function (string $suffix, array $payload) {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->system()->withNames()->create(['icon' => 'LeafIcon']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/activity-categories/'.$category->id)->assertOk();
    $this->patchJson('/api/admin/activity-categories/'.$category->id.$suffix, $payload)->assertForbidden();

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'icon' => 'LeafIcon', 'is_active' => false]);
})->with(['metadata' => ['', ['icon' => 'SeedIcon']], 'status' => ['/status', ['is_active' => true]]]);

test('super users may maintain system translations icons and status while the slug remains protected', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $category = ActivityCategory::factory()->system()->withNames()->create(['slug' => 'irrigation']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $path = '/api/admin/activity-categories/'.$category->id;

    $this->withToken($token)->patchJson($path, ['icon' => 'SeedIcon', 'translations' => ['en' => ['name' => 'Water management']]])
        ->assertOk()->assertJsonPath('data.translations.en.name', 'Water management');
    $this->patchJson($path.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
    $this->patchJson($path, ['slug' => 'another-identity'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson($path.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'slug' => 'irrigation', 'is_system' => true, 'icon' => 'SeedIcon', 'is_active' => false]);
});

test('activity category details use locale headers and English fallback with all saved translations', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->withHeader('X-Locale', 'hi')->getJson('/api/admin/activity-categories/'.$category->id.'?locale=en')
        ->assertOk()->assertJsonPath('data.name', 'सिंचाई')->assertJsonCount(2, 'data.translations');
    $this->withHeader('X-Locale', 'gu')->getJson('/api/admin/activity-categories/'.$category->id)
        ->assertOk()->assertJsonPath('data.name', 'Irrigation');
});

test('activity category searches find slugs and every configured translation without duplicate results', function (string $keyword, int $expectedCount) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames('Irrigation 50%_!', 'सिंचाई')->create(['slug' => 'water-management']);
    $category->translations()->create(['locale' => 'gu', 'name' => 'સિંચાઈ']);
    $category->translations()->create(['locale' => 'fr', 'name' => 'Unsupported locale']);
    ActivityCategory::factory()->withNames('Other category', 'अन्य')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/activity-categories?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount($expectedCount, 'data')->assertJsonPath('pagination.total', $expectedCount);
})->with([
    'slug' => ['water-management', 1], 'English name' => ['Irrigation', 1], 'Hindi name' => ['सिंचाई', 1],
    'Gujarati name' => ['સિંચાઈ', 1], 'literal wildcards' => ['50%_!', 1], 'escaped wildcards' => ['50%_x', 0],
    'unsupported stored locale' => ['Unsupported locale', 0], 'SQL punctuation' => ["' OR 1=1 --", 0],
]);

test('activity category lists filter active and system flags together', function (int $status, int $system) {
    $admin = AdminUser::factory()->create();
    foreach ([false, true] as $active) {
        foreach ([false, true] as $isSystem) {
            ActivityCategory::factory()->withNames()->create(['is_active' => $active, 'is_system' => $isSystem]);
        }
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/activity-categories?'.http_build_query(['status' => $status, 'is_system' => $system]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', (bool) $status)
        ->assertJsonPath('data.0.is_system', (bool) $system)->assertJsonPath('pagination.total', 1);
})->with(['inactive custom' => [0, 0], 'active custom' => [1, 0], 'inactive system' => [0, 1], 'active system' => [1, 1]]);

test('activity category lists sort English names before pagination with stable IDs and preserve allowed filters', function () {
    $admin = AdminUser::factory()->create();
    $zulu = ActivityCategory::factory()->withNames('Zulu Activity')->create();
    $alpha = ActivityCategory::factory()->withNames('Alpha Activity')->create();
    $secondAlpha = ActivityCategory::factory()->withNames('Alpha Activity')->create();
    $beta = ActivityCategory::factory()->withNames('Beta Activity')->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->withHeader('X-Locale', 'en')
        ->getJson('/api/admin/activity-categories?keyword=Activity&status=0&is_system=0&per_page=2&locale=hi&ignored=1')
        ->assertOk()->assertJsonPath('data.0.id', $alpha->id)->assertJsonPath('data.1.id', $secondAlpha->id)
        ->assertJsonPath('data.0.name', 'Alpha Activity')->assertJsonMissingPath('data.0.translations')
        ->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.last_page', 2);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('pagination')))->toBe([
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'next_page_url', 'prev_page_url',
    ]);
    expect($response->json('pagination.next_page_url'))->toContain('keyword=Activity', 'status=0', 'is_system=0', 'per_page=2')
        ->not->toContain('locale=', 'ignored=');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()
        ->assertJsonPath('data.0.id', $beta->id)->assertJsonPath('data.1.id', $zulu->id);
});

test('empty activity category pages and default page size follow the shared contract', function () {
    $admin = AdminUser::factory()->create();
    ActivityCategory::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/activity-categories')->assertOk()->assertJsonPath('pagination.per_page', 15);
    $this->getJson('/api/admin/activity-categories?page=2&per_page=1')->assertOk()->assertJsonPath('data', [])
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null)->assertJsonPath('pagination.total', 1);
});

test('invalid activity category list filters return 422', function (array $filters, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/activity-categories?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page zero' => [['page' => 0], 'page'], 'non integer page' => [['page' => 'x'], 'page'],
    'per page zero' => [['per_page' => 0], 'per_page'], 'per page maximum' => [['per_page' => 101], 'per_page'],
    'status boolean' => [['status' => 'yes'], 'status'], 'system boolean' => [['is_system' => 2], 'is_system'],
    'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'],
]);

test('activity categories require both nonempty mandatory names before activation', function (string $locale, ?string $name) {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames()->create();
    if ($name === null) {
        $category->translations()->where('locale', $locale)->delete();
    } else {
        $category->translations()->where('locale', $locale)->update(['name' => $name]);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/activity-categories/'.$category->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.'.$locale.'.name');

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'is_active' => false]);
})->with(['missing English' => ['en', null], 'missing Hindi' => ['hi', null], 'blank Hindi' => ['hi', ' ']]);

test('valid categories activate and status updates reject unrelated fields', function () {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $path = '/api/admin/activity-categories/'.$category->id.'/status';

    $this->withToken($token)->patchJson($path, ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.translations.en.name', 'Irrigation');
    $this->patchJson($path, ['is_active' => false, 'slug' => 'changed'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->patchJson($path, ['is_active' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->patchJson($path, [])->assertUnprocessable()->assertJsonValidationErrors('is_active');

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'is_active' => true]);
});

test('unreleased AI and expert categories remain drafts and cannot bypass activation through a slug edit', function (string $slug) {
    $admin = AdminUser::factory()->superUser()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $draft = $this->withToken($token)->postJson('/api/admin/activity-categories', activityCategoryPayload(['slug' => $slug]))
        ->assertCreated()->assertJsonPath('data.is_active', false);
    $active = ActivityCategory::factory()->published()->create(['slug' => 'released-activity']);

    $this->patchJson('/api/admin/activity-categories/'.$draft->json('data.id').'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    $this->patchJson('/api/admin/activity-categories/'.$active->id, ['slug' => $slug])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');

    $this->assertDatabaseHas('activity_categories', ['id' => $draft->json('data.id'), 'is_active' => false]);
    $this->assertDatabaseHas('activity_categories', ['id' => $active->id, 'slug' => 'released-activity', 'is_active' => true]);
})->with(['AI Doctor' => 'ask-ai-doctor', 'Expert' => 'ask-agricultural-expert']);

test('active categories cannot adopt an available unreleased slug through metadata updates', function (string $slug) {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->published()->create(['slug' => 'released-activity']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/activity-categories/'.$category->id, ['slug' => $slug])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'slug' => 'An active category cannot use an unreleased activity slug.',
        ]);

    $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'slug' => 'released-activity', 'is_active' => true]);
})->with(['AI Doctor' => 'ask-ai-doctor', 'Expert' => 'ask-agricultural-expert']);

test('deactivation preserves historical references and deletion is blocked even when foreign keys would erase or null them', function (string $deleteAction) {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->published()->create();
    Schema::create('activity_category_history', function (Blueprint $table) use ($deleteAction): void {
        $table->id();
        $table->unsignedBigInteger('activity_category_id')->nullable();
        $table->foreign('activity_category_id')->references('id')->on('activity_categories')->onDelete($deleteAction);
    });
    DB::table('activity_category_history')->insert(['activity_category_id' => $category->id]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    try {
        $this->withToken($token)->patchJson('/api/admin/activity-categories/'.$category->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->deleteJson('/api/admin/activity-categories/'.$category->id)->assertConflict()->assertJsonPath('pagination', null);
        $this->getJson('/api/admin/activity-categories/'.$category->id)->assertOk()->assertJsonPath('data.name', 'Irrigation');

        $this->assertModelExists($category);
        $this->assertDatabaseHas('activity_category_history', ['activity_category_id' => $category->id]);
        $this->assertDatabaseCount('activity_category_translations', 2);
    } finally {
        Schema::dropIfExists('activity_category_history');
    }
})->with(['restrict' => 'restrict', 'cascade' => 'cascade', 'set null' => 'set null']);

test('system and active categories cannot be deleted and return 409', function (bool $system, bool $active) {
    $admin = AdminUser::factory()->superUser()->create();
    $category = ActivityCategory::factory()->withNames()->create(['is_system' => $system, 'is_active' => $active]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/activity-categories/'.$category->id)->assertConflict()->assertJsonPath('status', false)
        ->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertModelExists($category);
    $this->assertDatabaseCount('activity_category_translations', 2);
})->with(['protected system' => [true, false], 'active custom' => [false, true]]);

test('deleting an unused inactive custom category removes only its own translations', function () {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames()->create();
    $other = ActivityCategory::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/activity-categories/'.$category->id)->assertOk()
        ->assertExactJson(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Activity category deleted successfully.']);

    $this->assertModelMissing($category);
    $this->assertModelExists($other);
    $this->assertDatabaseMissing('activity_category_translations', ['activity_category_id' => $category->id]);
    $this->assertDatabaseCount('activity_category_translations', 2);
});

test('activity category creation rolls back the master and earlier translations after a failed write', function () {
    $admin = AdminUser::factory()->create();
    DB::unprepared("CREATE TRIGGER fail_activity_translation_insert BEFORE INSERT ON activity_category_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/activity-categories', activityCategoryPayload())->assertInternalServerError()
            ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unable to create the activity category. Please try again later.']);

        $this->assertDatabaseCount('activity_categories', 0);
        $this->assertDatabaseCount('activity_category_translations', 0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_activity_translation_insert');
    }
});

test('failed translation updates roll back category metadata and preceding translation updates', function () {
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames()->create(['slug' => 'irrigation', 'icon' => 'LeafIcon']);
    DB::unprepared("CREATE TRIGGER fail_activity_translation_update BEFORE UPDATE ON activity_category_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson('/api/admin/activity-categories/'.$category->id, [
                'slug' => 'must-rollback', 'icon' => 'SeedIcon',
                'translations' => ['en' => ['name' => 'Changed English'], 'hi' => ['name' => 'Changed Hindi']],
            ])->assertInternalServerError();

        $this->assertDatabaseHas('activity_categories', ['id' => $category->id, 'slug' => 'irrigation', 'icon' => 'LeafIcon']);
        $this->assertDatabaseHas('activity_category_translations', ['activity_category_id' => $category->id, 'locale' => 'en', 'name' => 'Irrigation']);
        $this->assertDatabaseHas('activity_category_translations', ['activity_category_id' => $category->id, 'locale' => 'hi', 'name' => 'सिंचाई']);
    } finally {
        DB::unprepared('DROP TRIGGER fail_activity_translation_update');
    }
});

test('database constraints prevent duplicate slugs and category locale pairs', function (string $constraint) {
    $category = ActivityCategory::factory()->withNames()->create(['slug' => 'irrigation']);

    expect(fn () => $constraint === 'slug'
        ? ActivityCategory::factory()->create(['slug' => 'irrigation'])
        : $category->translations()->create(['locale' => 'en', 'name' => 'Duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);
})->with(['slug' => 'slug', 'locale pair' => 'locale']);

test('published activity category selections exclude inactive incomplete and unreleased records while history remains resolvable', function () {
    $published = ActivityCategory::factory()->published()->create();
    $inactive = ActivityCategory::factory()->withNames()->create();
    ActivityCategory::factory()->create(['is_active' => true]);
    ActivityCategory::factory()->published()->create(['slug' => 'ask-ai-doctor']);
    ActivityCategory::factory()->published()->create(['slug' => 'ask-agricultural-expert']);
    app()->setLocale('hi');

    $selection = ActivityCategory::published()->get();

    expect($selection->modelKeys())->toBe([$published->id]);
    expect($selection->first()->translatedName())->toBe('सिंचाई');
    expect(ActivityCategory::findOrFail($inactive->id)->load('translations')->translatedName())->toBe('सिंचाई');
});

test('activity category list rendering has no per-record translation queries', function () {
    $admin = AdminUser::factory()->create();
    ActivityCategory::factory()->withNames()->count(3)->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->getJson('/api/admin/activity-categories')->assertOk()->assertJsonCount(3, 'data');

        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "activity_categories"'));
        expect($queries)->toHaveCount(2);
        $translationQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "activity_category_translations"'));
        expect($translationQueries)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('activity category resources never query unloaded translations', function () {
    $category = ActivityCategory::factory()->make();
    DB::enableQueryLog();

    try {
        $data = (new ActivityCategoryResource($category))->resolve(Request::create('/'));

        expect($data['name'])->toBeNull();
        expect($data)->not->toHaveKey('translations');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('missing activity categories use the shared 404 JSON envelope without an Accept header', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->get('/api/admin/activity-categories/999')->assertNotFound()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

test('activity category listing names follow locale headers with English fallback', function (array $headers, string $expectedName) {
    config()->set('languages.supported.gu', 'Gujarati');
    config()->set('languages.supported.mr', 'Marathi');
    $admin = AdminUser::factory()->create();
    $category = ActivityCategory::factory()->withNames('Irrigation', 'सिंचाई')->create();
    $category->translations()->create(['locale' => 'gu', 'name' => 'સિંચાઈ']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/activity-categories?locale=en')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $category->id)->assertJsonPath('data.0.name', $expectedName)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'English default' => [[], 'Irrigation'],
    'Hindi locale header' => [['X-Locale' => 'hi'], 'सिंचाई'],
    'Hindi language header' => [['Accept-Language' => 'hi'], 'सिंचाई'],
    'configured Gujarati' => [['X-Locale' => 'gu'], 'સિંચાઈ'],
    'missing Marathi fallback' => [['X-Locale' => 'mr'], 'Irrigation'],
    'unsupported locale fallback' => [['X-Locale' => 'fr', 'Accept-Language' => 'hi'], 'Irrigation'],
    'locale header precedence' => [['X-Locale' => 'en', 'Accept-Language' => 'hi'], 'Irrigation'],
]);

test('activity category listings sort the requested translated names before pagination with stable IDs', function () {
    $admin = AdminUser::factory()->create();
    $irrigation = ActivityCategory::factory()->withNames('Alpha activity', 'इरीगेशन')->create();
    $assessment = ActivityCategory::factory()->withNames('Zulu activity', 'आकलन')->create();
    $observation = ActivityCategory::factory()->withNames('Middle activity', 'अवलोकन')->create();
    $secondAssessment = ActivityCategory::factory()->withNames('Delta activity', 'आकलन')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/activity-categories?per_page=2')->assertOk()
        ->assertJsonPath('data.0.id', $observation->id)->assertJsonPath('data.0.name', 'अवलोकन')
        ->assertJsonPath('data.1.id', $assessment->id)->assertJsonPath('data.1.name', 'आकलन')
        ->assertJsonPath('pagination.total', 4)->assertJsonPath('pagination.last_page', 2);
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()
        ->assertJsonPath('data.0.id', $secondAssessment->id)->assertJsonPath('data.1.id', $irrigation->id);
});

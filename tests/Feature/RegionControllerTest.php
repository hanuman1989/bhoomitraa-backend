<?php

use App\Http\Resources\Admin\RegionResource;
use App\Models\AdminUser;
use App\Models\Region;
use App\Models\RegionTranslation;
use App\Models\User;
use Database\Seeders\RegionSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

test('region endpoints return 401 without an admin bearer token', function (string $method, bool $single) {
    $region = Region::factory()->withEnglishName('India')->create();

    $response = $this->json($method, '/api/admin/regions'.($single ? '/'.$region->id : ''));

    $response->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);
    $this->assertModelExists($region);
    $this->assertDatabaseCount('regions', 1);
})->with([
    'index' => ['GET', false], 'store' => ['POST', false], 'show' => ['GET', true],
    'update' => ['PATCH', true], 'destroy' => ['DELETE', true],
]);

test('frontend tokens return 401 on region mutations', function (string $method, bool $single) {
    $region = Region::factory()->withEnglishName('India')->create();
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'])->plainTextToken;

    $response = $this->withToken($token)->json($method, '/api/admin/regions'.($single ? '/'.$region->id : ''));

    $response->assertUnauthorized();
    $this->assertDatabaseCount('regions', 1);
    $this->assertModelExists($region);
})->with(['store' => ['POST', false], 'update' => ['PATCH', true], 'destroy' => ['DELETE', true]]);

test('region management permits only active admin identities', function () {
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-regions'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->superUser()->make())->allows('manage-regions'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->inactive()->make())->allows('manage-regions'))->toBeFalse();
    expect(Gate::forUser(User::factory()->make())->allows('manage-regions'))->toBeFalse();
});

test('region mutations return 403 when the management gate refuses access', function (string $method, bool $single) {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();
    Gate::define('manage-regions', fn (Authenticatable $user): bool => false);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/regions'.($single ? '/'.$region->id : ''));

    $response->assertForbidden();
    $this->assertModelExists($region);
    $this->assertDatabaseCount('regions', 1);
})->with(['store' => ['POST', false], 'update' => ['PATCH', true], 'destroy' => ['DELETE', true]]);

test('inactive admins and tokens without admin ability receive 403', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $response = $this->withToken($admin->createToken('test', $abilities)->plainTextToken)->getJson('/api/admin/regions');

    $response->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('admins create countries with English and optional Hindi translations', function (bool $withHindi) {
    $admin = AdminUser::factory()->create();
    $this->travelTo('2026-10-31 09:00:00.123456');
    $translations = ['en' => ['name' => 'India']];
    if ($withHindi) {
        $translations['hi'] = ['name' => 'भारत'];
    }

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
        'region_type' => 'country', 'code' => 'IN', 'is_active' => true, 'translations' => $translations,
        'latitude' => '26.9124000', 'longitude' => '75.7873000',
        'name' => 'Must not be stored', 'id' => 999,
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'India')->assertJsonPath('data.parent_id', null)
        ->assertJsonPath('data.latitude', '26.9124000')->assertJsonPath('data.longitude', '75.7873000')
        ->assertJsonPath('data.added_at', '31 Oct 2026 09:00 AM')
        ->assertJsonPath('data.created_at', '2026-10-31T09:00:00.123456Z')
        ->assertJsonPath('data.translations.en.name', 'India')->assertJsonPath('pagination', null);
    if ($withHindi) {
        $response->assertJsonPath('data.translations.hi.name', 'भारत');
    }
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('regions', ['id' => $response->json('data.id'), 'code' => 'IN', 'parent_id' => null]);
    $this->assertDatabaseCount('region_translations', count($translations));
})->with(['English only' => [false], 'English and Hindi' => [true]]);

test('admins create each region type beneath its required parent', function (string $parentType, string $type) {
    $admin = AdminUser::factory()->create();
    $parent = Region::factory()->withEnglishName('Parent')->create(['region_type' => $parentType]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
        'parent_id' => $parent->id, 'region_type' => $type, 'is_active' => true, 'translations' => ['en' => ['name' => 'Child']],
    ]);

    $response->assertCreated()->assertJsonPath('data.parent.id', $parent->id)->assertJsonPath('data.parent.name', 'Parent');
    $this->assertDatabaseHas('regions', ['id' => $response->json('data.id'), 'region_type' => $type, 'parent_id' => $parent->id]);
})->with([
    'state' => ['country', 'state'], 'district' => ['state', 'district'],
    'subdistrict' => ['district', 'subdistrict'], 'village' => ['subdistrict', 'village'],
]);

test('missing required creation fields return 422 in the standard envelope', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', []);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'region_type', 'is_active', 'translations', 'translations.en.name' => 'The English region name is required.',
    ])->assertJsonPath('data', null)->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.');
    $this->assertDatabaseCount('regions', 0);
});

test('invalid region payloads return 422 without persistence', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $payload = array_replace([
        'region_type' => 'country', 'is_active' => true, 'translations' => ['en' => ['name' => 'India']],
    ], $changes);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('regions', 0);
    $this->assertDatabaseCount('region_translations', 0);
})->with([
    'unknown type' => [['region_type' => 'city'], 'region_type'],
    'missing parent' => [['region_type' => 'state'], 'parent_id'],
    'nonexistent parent' => [['parent_id' => 99999], 'parent_id'],
    'noninteger parent' => [['parent_id' => 'abc'], 'parent_id'],
    'oversized code' => [['code' => str_repeat('a', 51)], 'code'],
    'nonstring code' => [['code' => 123], 'code'],
    'latitude above range' => [['latitude' => 91], 'latitude'],
    'latitude below range' => [['latitude' => -91], 'latitude'],
    'longitude above range' => [['longitude' => 181], 'longitude'],
    'longitude below range' => [['longitude' => -181], 'longitude'],
    'nonnumeric latitude' => [['latitude' => 'north'], 'latitude'],
    'nonnumeric longitude' => [['longitude' => 'east'], 'longitude'],
    'invalid active flag' => [['is_active' => 'active'], 'is_active'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'India'], 'fr' => ['name' => 'Inde']]], 'translations'],
    'missing English' => [['translations' => ['hi' => ['name' => 'भारत']]], 'translations.en.name'],
    'empty English' => [['translations' => ['en' => ['name' => ' ']]], 'translations.en.name'],
    'oversized name' => [['translations' => ['en' => ['name' => str_repeat('a', 151)]]], 'translations.en.name'],
    'nonstring name' => [['translations' => ['en' => ['name' => 123]]], 'translations.en.name'],
    'missing supplied Hindi name' => [['translations' => ['en' => ['name' => 'India'], 'hi' => []]], 'translations.hi'],
    'unknown nested field' => [['translations' => ['en' => ['name' => 'India', 'region_id' => 999]]], 'translations.en'],
    'nonobject translation' => [['translations' => ['en' => ['name' => 'India'], 'hi' => 'भारत']], 'translations.hi'],
]);

test('incorrect parent types and parents on countries return 422', function (string $type) {
    $admin = AdminUser::factory()->create();
    $parent = Region::factory()->withEnglishName('India')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
        'region_type' => $type, 'parent_id' => $parent->id, 'is_active' => true, 'translations' => ['en' => ['name' => 'Invalid']],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->assertDatabaseCount('regions', 1);
})->with(['country with parent' => ['country'], 'district under country' => ['district']]);

test('updates preserve omitted translations and fields while adding or replacing supplied names', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create(['code' => 'IN']);
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->patchJson('/api/admin/regions/'.$region->id, [
        'translations' => ['en' => ['name' => 'Republic of India']], 'is_active' => false,
    ]);

    $response->assertOk()->assertJsonPath('data.name', 'भारत')->assertJsonPath('data.translations.en.name', 'Republic of India')
        ->assertJsonPath('data.translations.hi.name', 'भारत')->assertJsonPath('data.code', 'IN')->assertJsonPath('data.is_active', false);
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'hi', 'name' => 'भारत']);
    $this->assertDatabaseCount('region_translations', 2);
    $this->assertDatabaseHas('regions', ['id' => $region->id, 'is_active' => false]);
});

test('master-only updates and adding a non-English locale do not require resubmitting English', function (array $changes) {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$region->id, $changes);

    $response->assertOk()->assertJsonPath('data.translations.en.name', 'India');
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en', 'name' => 'India']);
})->with(['master only' => [['code' => 'IN']], 'add Hindi' => [['translations' => ['hi' => ['name' => 'भारत']]]]]);

test('emptying translations or unsupported update locales returns 422 and preserves English', function (mixed $translations) {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$region->id, ['translations' => $translations]);

    $response->assertUnprocessable();
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en', 'name' => 'India']);
})->with(['null translations' => [null], 'null English' => [['en' => null]], 'empty English name' => [['en' => ['name' => '']]], 'unsupported locale' => [['fr' => ['name' => 'Inde']]]]);

test('self-parenting and moving under a descendant return 422', function (bool $self) {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$country->id, [
        'region_type' => 'district', 'parent_id' => $self ? $country->id : $state->id,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->assertDatabaseHas('regions', ['id' => $country->id, 'region_type' => 'country', 'parent_id' => null]);
})->with(['self' => [true], 'descendant' => [false]]);

test('a preexisting circular ancestor chain returns 422 without looping', function () {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    DB::table('regions')->where('id', $country->id)->update(['parent_id' => $state->id]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$state->id, ['code' => 'RJ']);

    $response->assertUnprocessable()->assertJsonValidationErrors('parent_id');
    $this->assertDatabaseHas('regions', ['id' => $state->id, 'code' => null]);
});

test('changing a region type that would invalidate children returns 422', function () {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $otherCountry = Region::factory()->withEnglishName('Other country')->create();
    Region::factory()->under($country)->withEnglishName('Rajasthan')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$country->id, [
        'region_type' => 'state', 'parent_id' => $otherCountry->id,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('region_type');
    $this->assertDatabaseHas('regions', ['id' => $country->id, 'region_type' => 'country', 'parent_id' => null]);
});

test('valid reparenting preserves the hierarchy and translations', function () {
    $admin = AdminUser::factory()->create();
    $first = Region::factory()->withEnglishName('First country')->create();
    $second = Region::factory()->withEnglishName('Second country')->create();
    $state = Region::factory()->under($first)->withEnglishName('State')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/regions/'.$state->id, ['parent_id' => $second->id, 'latitude' => null]);

    $response->assertOk()->assertJsonPath('data.parent.name', 'Second country')->assertJsonPath('data.name', 'State');
    $this->assertDatabaseHas('regions', ['id' => $state->id, 'parent_id' => $second->id]);
});

test('admin lists sort by English by default with stable IDs and no duplicate translation rows', function () {
    $admin = AdminUser::factory()->create();
    $zulu = Region::factory()->withEnglishName('Zulu')->create();
    $alpha = Region::factory()->withEnglishName('Alpha')->create();
    $alpha->translations()->create(['locale' => 'hi', 'name' => 'भारत']);
    $same = Region::factory()->withEnglishName('Alpha')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/regions?per_page=2');

    $response->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $alpha->id)->assertJsonPath('data.1.id', $same->id)->assertJsonPath('pagination.last_page', 2);
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2', 'page=2');
});

test('requested locale search and sorting use the same English fallback as display', function () {
    $admin = AdminUser::factory()->create();
    $translated = Region::factory()->withEnglishName('Alpha')->create();
    $translated->translations()->create(['locale' => 'hi', 'name' => 'Zulu']);
    $fallback = Region::factory()->withEnglishName('Beta')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/regions');

    $response->assertOk()->assertJsonPath('data.0.id', $fallback->id)->assertJsonPath('data.0.name', 'Beta')
        ->assertJsonPath('data.1.id', $translated->id)->assertJsonPath('data.1.name', 'Zulu');
});

test('translated name searches support Hindi, English fallback and literal wildcards', function (string $keyword, string $locale, string $name) {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName($name)->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    Region::factory()->withEnglishName('Unrelated')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)->getJson('/api/admin/regions?'.http_build_query([
        'keyword' => $keyword,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $region->id);
})->with([
    'Hindi' => ['राज', 'hi', 'Rajasthan'], 'English' => ['Rajas', 'en', 'Rajasthan'],
    'literal percent' => ['%', 'en', '100% Region'], 'literal underscore' => ['_', 'en', 'Test_Region'],
    'literal escape' => ['!', 'en', 'Test!Region'],
]);

test('search falls back to English when the requested translation is missing', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('Rajasthan')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/regions?keyword=Rajas');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $region->id)->assertJsonPath('data.0.name', 'Rajasthan');
});

test('region lists combine parent type and active filters before pagination', function () {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $first = Region::factory()->under($country)->withEnglishName('Alpha')->create();
    $second = Region::factory()->under($country)->withEnglishName('Beta')->create();
    Region::factory()->under($country)->withEnglishName('Inactive')->create(['is_active' => false]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/regions?'.http_build_query([
        'parent_id' => $country->id, 'region_type' => 'state', 'is_active' => 1, 'per_page' => 1, 'page' => 2,
    ]));

    $response->assertOk()->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.current_page', 2)
        ->assertJsonPath('data.0.id', $second->id)->assertJsonCount(1, 'data');
    expect($response->json('pagination.prev_page_url'))->toContain('parent_id='.$country->id, 'is_active=1');
    expect($response->json('pagination.prev_page_url'))->not->toContain('locale=');
});

test('parent filters take precedence over region type filters', function (array $filters) {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    $otherCountry = Region::factory()->withEnglishName('Other country')->create();
    Region::factory()->under($otherCountry)->withEnglishName('Other state')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/regions?'.http_build_query(['parent_id' => $country->id, ...$filters]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $state->id)
        ->assertJsonPath('pagination.total', 1);
})->with(['parent only' => [[]], 'both filters' => [['region_type' => 'country']]]);

test('region type filters apply when no parent is selected', function (array $filters) {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    Region::factory()->under($state)->withEnglishName('Jaipur')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/regions?'.http_build_query(['region_type' => 'state', ...$filters]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $state->id)
        ->assertJsonPath('pagination.total', 1);
})->with(['parent omitted' => [[]], 'empty parent' => [['parent_id' => '']]]);

test('null parent and false active filters select inactive roots', function () {
    $admin = AdminUser::factory()->create();
    $inactive = Region::factory()->withEnglishName('Inactive')->create(['is_active' => false]);
    Region::factory()->withEnglishName('Active')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/regions?parent_id=&is_active=0');

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inactive->id);
});

test('invalid region filters return 422', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/regions?'.http_build_query($query));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'invalid type' => [['region_type' => 'city'], 'region_type'], 'invalid parent' => [['parent_id' => 'x'], 'parent_id'],
    'invalid active' => [['is_active' => 'yes'], 'is_active'], 'array keyword' => [['keyword' => ['x']], 'keyword'],
    'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'], 'zero page' => [['page' => 0], 'page'],
    'zero size' => [['per_page' => 0], 'per_page'], 'excessive size' => [['per_page' => 101], 'per_page'],
]);

test('empty and out of range pages preserve the response contract', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/regions?page=99');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('region details include every translation and localized parent without lazy loading', function () {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $country->translations()->create(['locale' => 'hi', 'name' => 'भारत']);
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    $state->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    Model::preventLazyLoading();
    try {
        $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
            ->getJson('/api/admin/regions/'.$state->id);

        $response->assertOk()->assertJsonPath('data.name', 'राजस्थान')->assertJsonPath('data.parent.name', 'भारत')
            ->assertJsonPath('data.translations.en.locale', 'en')->assertJsonPath('data.translations.hi.name', 'राजस्थान');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('region lists use a bounded number of queries as record counts increase', function (string $uri) {
    $admin = AdminUser::factory()->create();
    Region::factory()->count(5)->withEnglishName('Country')->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();

    try {
        $response = $this->getJson($uri);
        $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'region_translations'));
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    $response->assertOk()->assertJsonCount(5, 'data');
    expect($queries)->toHaveCount(3);
})->with(['admin list' => ['/api/admin/regions'], 'public list' => ['/api/regions']]);

test('deletion returns 409 for regions with children without deleting translations', function () {
    $admin = AdminUser::factory()->create();
    $country = Region::factory()->withEnglishName('India')->create();
    Region::factory()->under($country)->withEnglishName('Rajasthan')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/regions/'.$country->id);

    $response->assertConflict()->assertJsonPath('status', false)->assertJsonPath('data', null);
    $this->assertModelExists($country);
    $this->assertDatabaseCount('region_translations', 2);
});

test('deletion returns 409 when another business table restricts the region', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();
    Schema::create('region_test_references', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
    });
    DB::table('region_test_references')->insert(['region_id' => $region->id]);

    try {
        $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/regions/'.$region->id);

        $response->assertConflict();
        $this->assertModelExists($region);
        $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en']);
    } finally {
        Schema::dropIfExists('region_test_references');
    }
});

test('deleting a leaf returns the standard 200 envelope and cascades translations', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/regions/'.$region->id);

    $response->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Region deleted successfully.',
    ]);
    $this->assertModelMissing($region);
    $this->assertDatabaseCount('region_translations', 0);
});

test('missing region records return the standard 404 envelope', function (string $method) {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->json($method, '/api/admin/regions/99999');

    $response->assertNotFound()->assertJsonPath('data', null)->assertJsonPath('pagination', null)->assertJsonPath('message', 'Resource not found.');
})->with(['GET', 'PATCH', 'DELETE']);

test('a translation persistence failure rolls back the master and earlier translations', function () {
    $admin = AdminUser::factory()->create();
    RegionTranslation::creating(function (RegionTranslation $translation): void {
        if ($translation->locale === 'hi') {
            throw new RuntimeException('Simulated translation failure.');
        }
    });
    try {
        $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
            'region_type' => 'country', 'is_active' => true,
            'translations' => ['en' => ['name' => 'India'], 'hi' => ['name' => 'भारत']],
        ]);

        $response->assertInternalServerError()->assertJsonPath('message', 'An unexpected error occurred.');
        $this->assertDatabaseCount('regions', 0);
        $this->assertDatabaseCount('region_translations', 0);
    } finally {
        RegionTranslation::flushEventListeners();
    }
});

test('region hierarchy changes are rechecked when persistence follows concurrent changes', function () {
    $country = Region::factory()->withEnglishName('India')->create();
    $other = Region::factory()->withEnglishName('Other')->create();
    $stale = $country->fresh();
    Region::factory()->under($country)->withEnglishName('State')->create();

    expect(fn () => $stale->saveWithTranslations(['region_type' => 'state', 'parent_id' => $other->id], []))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('regions', ['id' => $country->id, 'region_type' => 'country', 'parent_id' => null]);
});

test('optional region seeding is repeatable and preserves administrator content', function () {
    $country = Region::factory()->withEnglishName('Custom India')->create(['code' => 'IN']);

    $this->seed(RegionSeeder::class);
    $this->seed(RegionSeeder::class);

    $this->assertDatabaseCount('regions', 1);
    $this->assertDatabaseCount('region_translations', 2);
    $this->assertDatabaseHas('region_translations', ['region_id' => $country->id, 'locale' => 'en', 'name' => 'Custom India']);
});

test('failed translation updates roll back master fields and earlier translation changes', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create(['code' => 'IN']);
    RegionTranslation::creating(function (RegionTranslation $translation): void {
        if ($translation->locale === 'hi') {
            throw new RuntimeException('Simulated translation failure.');
        }
    });

    try {
        $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/regions/'.$region->id, [
            'code' => 'CHANGED', 'translations' => ['en' => ['name' => 'Changed'], 'hi' => ['name' => 'भारत']],
        ]);

        $response->assertInternalServerError();
        $this->assertDatabaseHas('regions', ['id' => $region->id, 'code' => 'IN']);
        $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en', 'name' => 'India']);
        $this->assertDatabaseCount('region_translations', 1);
    } finally {
        RegionTranslation::flushEventListeners();
    }
});

test('region migrations roll back in dependency order and can be applied again', function () {
    config(['database.connections.region_migration_test' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);

    try {
        DB::usingConnection('region_migration_test', function (): void {
            $master = require database_path('migrations/2026_10_31_094454_create_regions_table.php');
            $translations = require database_path('migrations/2026_10_31_094455_create_region_translations_table.php');
            $master->up();
            $translations->up();
            (new Region)->saveWithTranslations(['region_type' => 'country', 'is_active' => true], ['en' => ['name' => 'India']]);

            $translations->down();
            $master->down();

            expect(Schema::hasTable('regions'))->toBeFalse();
            expect(Schema::hasTable('region_translations'))->toBeFalse();
            $master->up();
            $translations->up();
            $region = (new Region)->saveWithTranslations(['region_type' => 'country', 'is_active' => true], ['en' => ['name' => 'India']]);
            expect($region->translatedName())->toBe('India');
        });
    } finally {
        DB::purge('region_migration_test');
    }
});

test('public region details use X-Locale without exposing translation maps', function (string $locale, string $expectedName) {
    $region = Region::factory()->withEnglishName('India')->create(['code' => 'IN']);
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withHeader('X-Locale', $locale)->getJson('/api/regions/'.$region->id);

    $response->assertOk()->assertJsonPath('data.id', $region->id)->assertJsonPath('data.name', $expectedName)
        ->assertJsonPath('data.code', 'IN')->assertJsonPath('data.parent_id', null)->assertJsonPath('pagination', null)
        ->assertJsonMissingPath('data.translations');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
})->with(['English' => ['en', 'India'], 'Hindi' => ['hi', 'भारत']]);

test('public listings preserve filters localized sorting fallback and pagination', function () {
    $country = Region::factory()->withEnglishName('India')->create();
    $first = Region::factory()->under($country)->withEnglishName('Zulu')->create();
    $first->translations()->create(['locale' => 'hi', 'name' => 'Alpha']);
    $second = Region::factory()->under($country)->withEnglishName('Beta')->create();
    Region::factory()->under($country)->withEnglishName('Inactive')->create(['is_active' => false]);

    $response = $this->withHeader('X-Locale', 'hi')->getJson('/api/regions?'.http_build_query([
        'parent_id' => $country->id, 'region_type' => 'state', 'is_active' => 1, 'per_page' => 1, 'page' => 2,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.0.name', 'Beta')->assertJsonPath('pagination.total', 2)
        ->assertJsonMissingPath('data.0.translations');
    expect($response->json('pagination.prev_page_url'))->toContain('parent_id='.$country->id, 'is_active=1');
});

test('public translated name searches use the middleware locale', function () {
    $region = Region::factory()->withEnglishName('Rajasthan')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    Region::factory()->withEnglishName('Other')->create();

    $response = $this->withHeader('X-Locale', 'hi')->getJson('/api/regions?'.http_build_query(['keyword' => 'राज']));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $region->id)
        ->assertJsonMissingPath('data.0.translations');
});

test('public routes cannot mutate regions', function (string $method, bool $single) {
    $region = Region::factory()->withEnglishName('India')->create();

    $response = $this->json($method, '/api/regions'.($single ? '/'.$region->id : ''), [
        'region_type' => 'country', 'is_active' => true, 'translations' => ['en' => ['name' => 'Changed']],
    ]);

    $response->assertMethodNotAllowed();
    $this->assertModelExists($region);
    $this->assertDatabaseCount('regions', 1);
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'name' => 'India']);
})->with(['create' => ['POST', false], 'update' => ['PUT', true], 'partial update' => ['PATCH', true], 'delete' => ['DELETE', true]]);

test('public responses omit translations even when an admin token is supplied', function () {
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->withHeader('X-Locale', 'hi')->getJson('/api/regions/'.$region->id);

    $response->assertOk()->assertJsonPath('data.name', 'भारत')->assertJsonMissingPath('data.translations');
});

test('header precedence and unsupported header fallback follow the existing middleware', function (array $headers, string $name) {
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withHeaders($headers)->getJson('/api/regions/'.$region->id);

    $response->assertOk()->assertJsonPath('data.name', $name);
})->with([
    'no headers uses default' => [[], 'India'],
    'X-Locale overrides Accept-Language' => [['X-Locale' => 'hi', 'Accept-Language' => 'en'], 'भारत'],
    'Accept-Language when X-Locale absent' => [['Accept-Language' => 'hi'], 'भारत'],
    'weighted Accept-Language' => [['Accept-Language' => 'hi;q=0.8,en;q=0.3'], 'भारत'],
    'unsupported X-Locale falls back to default' => [['X-Locale' => 'fr', 'Accept-Language' => 'hi'], 'India'],
]);

test('legacy query locales do not override the active middleware locale', function (mixed $locale) {
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withHeader('X-Locale', 'hi')->getJson('/api/regions/'.$region->id.'?'.http_build_query(['locale' => $locale]));

    $response->assertOk()->assertJsonPath('data.name', 'भारत');
})->with(['English query' => ['en'], 'unsupported query' => ['fr'], 'array query' => [['en']]]);

test('a configured non-English default is used for absent or unsupported language headers', function (array $headers) {
    config(['languages.default' => 'hi']);
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);

    $response = $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->withHeaders($headers)->getJson('/api/regions/'.$region->id);

    $response->assertOk()->assertJsonPath('data.name', 'भारत');
})->with([
    'no header' => [[]], 'unsupported X-Locale' => [['X-Locale' => 'fr']],
    'unsupported Accept-Language' => [['Accept-Language' => 'fr']],
]);

test('create validation requires the configured default translation rather than English', function () {
    config(['languages.default' => 'hi']);
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
        'region_type' => 'country', 'is_active' => true, 'translations' => ['en' => ['name' => 'India']],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors([
        'translations.hi.name' => 'The हिन्दी region name is required.',
    ]);
    $this->assertDatabaseCount('regions', 0);
});

test('regions can be created with only the configured non-English default translation', function () {
    config(['languages.default' => 'hi']);
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/regions', [
        'region_type' => 'country', 'is_active' => true, 'translations' => ['hi' => ['name' => 'भारत']],
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'भारत')->assertJsonPath('data.translations.hi.name', 'भारत');
    $this->assertDatabaseHas('region_translations', ['region_id' => $response->json('data.id'), 'locale' => 'hi', 'name' => 'भारत']);
    $this->assertDatabaseCount('region_translations', 1);
});

test('partial updates preserve the configured default and cannot empty it', function () {
    config(['languages.default' => 'hi']);
    $admin = AdminUser::factory()->create();
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);

    $response = $this->patchJson('/api/admin/regions/'.$region->id, ['translations' => ['en' => ['name' => 'Republic of India']]]);

    $response->assertOk()->assertJsonPath('data.translations.hi.name', 'भारत');
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en', 'name' => 'Republic of India']);

    $this->patchJson('/api/admin/regions/'.$region->id, ['translations' => ['hi' => ['name' => '']]])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.hi.name');
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'hi', 'name' => 'भारत']);
});

test('additional configured languages can be saved retrieved and updated without duplicates', function () {
    config(['languages.supported' => ['en' => 'English', 'hi' => 'हिन्दी', 'mr' => 'मराठी']]);
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'mr');

    $created = $this->postJson('/api/admin/regions', [
        'region_type' => 'country', 'is_active' => true,
        'translations' => ['en' => ['name' => 'India'], 'hi' => ['name' => 'भारत'], 'mr' => ['name' => 'भारत देश']],
    ]);

    $created->assertCreated()->assertJsonPath('data.name', 'भारत देश')->assertJsonCount(3, 'data.translations');
    $id = $created->json('data.id');
    $translationId = RegionTranslation::where('region_id', $id)->where('locale', 'mr')->value('id');

    $updated = $this->patchJson('/api/admin/regions/'.$id, ['translations' => ['mr' => ['name' => 'भारत']]]);

    $updated->assertOk()->assertJsonPath('data.name', 'भारत')->assertJsonPath('data.translations.en.name', 'India');
    $this->assertDatabaseHas('region_translations', ['id' => $translationId, 'region_id' => $id, 'locale' => 'mr', 'name' => 'भारत']);
    $this->assertDatabaseCount('region_translations', 3);
    $this->getJson('/api/regions/'.$id)->assertOk()->assertJsonPath('data.name', 'भारत')->assertJsonMissingPath('data.translations');
});

test('sorting search and display fall back to a configured non-English default', function () {
    config(['languages.default' => 'hi', 'languages.supported' => ['en' => 'English', 'hi' => 'हिन्दी', 'mr' => 'मराठी']]);
    $first = Region::factory()->withEnglishName('Zulu')->create();
    $first->translations()->create(['locale' => 'hi', 'name' => 'Alpha Hindi']);
    $second = Region::factory()->withEnglishName('Alpha')->create();
    $second->translations()->create(['locale' => 'hi', 'name' => 'Zulu Hindi']);

    $response = $this->withHeader('X-Locale', 'mr')->getJson('/api/regions?keyword=Hindi');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.name', 'Alpha Hindi')->assertJsonPath('data.1.id', $second->id)
        ->assertJsonPath('data.1.name', 'Zulu Hindi');
});

test('missing translations safely return a null resolved name', function () {
    $region = Region::factory()->create();

    $response = $this->withHeader('X-Locale', 'hi')->getJson('/api/regions/'.$region->id);

    $response->assertOk()->assertJsonPath('data.name', null)->assertJsonMissingPath('data.translations');
});

test('the translation constraint prevents duplicate region and locale pairs', function () {
    $region = Region::factory()->withEnglishName('India')->create();

    expect(fn () => $region->translations()->create(['locale' => 'en', 'name' => 'Duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('region_translations', 1);
    $this->assertDatabaseHas('region_translations', ['region_id' => $region->id, 'locale' => 'en', 'name' => 'India']);
});

test('model translation lookups load missing translations once and reuse them', function () {
    $region = Region::factory()->withEnglishName('India')->create();
    $region->translations()->create(['locale' => 'hi', 'name' => 'भारत']);
    $region = $region->fresh();
    app()->setLocale('hi');
    DB::enableQueryLog();

    try {
        expect($region->translatedName())->toBe('भारत');
        expect($region->translatedName('en'))->toBe('India');
        expect(DB::getQueryLog())->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('region resources never query unloaded translation relationships', function () {
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create()->fresh(['parent']);
    DB::enableQueryLog();

    try {
        $data = (new RegionResource($state))->resolve(Request::create('/api/regions/'.$state->id));

        expect($data['name'])->toBeNull();
        expect($data['parent']['name'])->toBeNull();
        expect(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

<?php

use App\Http\Resources\Admin\SeasonResource;
use App\Models\AdminUser;
use App\Models\Season;
use App\Models\SeasonTranslation;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function seasonPayload(array $overrides = []): array
{
    return array_replace([
        'slug' => 'rabi',
        'translations' => [
            'en' => ['name' => 'Rabi', 'description' => 'An agricultural season category.'],
            'hi' => ['name' => 'रबी', 'description' => 'एक कृषि मौसम श्रेणी।'],
        ],
    ], $overrides);
}

test('season endpoints return 401 without an admin bearer token', function (string $method, string $suffix) {
    $season = Season::factory()->withNames()->create();

    $this->json($method, '/api/admin/seasons'.str_replace('{id}', (string) $season->id, $suffix))
        ->assertUnauthorized()
        ->assertExactJson([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'Unauthenticated.',
        ]);
})->with([
    'index' => ['GET', ''],
    'store' => ['POST', ''],
    'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'],
    'destroy' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('season administration rejects frontend identities with 401', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/seasons')->assertUnauthorized();
});

test('season administration rejects inactive admins and missing abilities with 403', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/seasons')->assertForbidden();
})->with([
    'inactive admin' => [false, ['admin']],
    'wrong ability' => [true, ['frontend']],
]);

test('season mutations return 403 when the management gate denies access', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    Gate::define('manage-seasons', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/seasons'.str_replace('{id}', (string) $season->id, $suffix), seasonPayload())
        ->assertForbidden();

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'slug' => $season->slug, 'is_active' => false]);
    $this->assertDatabaseCount('season_translations', 2);
})->with([
    'store' => ['POST', ''],
    'update' => ['PATCH', '/{id}'],
    'destroy' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('the season gate follows active admin permissions regardless of super user flag', function (bool $active, bool $superUser, bool $allowed) {
    $admin = AdminUser::factory()->make(['status' => $active, 'is_super_user' => $superUser]);

    expect(Gate::forUser($admin)->allows('manage-seasons'))->toBe($allowed);
    expect(Gate::forUser(User::factory()->make())->allows('manage-seasons'))->toBeFalse();
})->with([
    'active admin' => [true, false, true],
    'active super user' => [true, true, true],
    'inactive admin' => [false, false, false],
    'inactive super user' => [false, true, false],
]);

test('admins create inactive seasons with English Hindi and precise timestamps', function () {
    $admin = AdminUser::factory()->create(['is_super_user' => false]);
    $this->travelTo('2026-11-05 05:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/seasons', seasonPayload())
        ->assertCreated()
        ->assertJsonPath('data.slug', 'rabi')
        ->assertJsonPath('data.name', 'Rabi')
        ->assertJsonPath('data.description', 'An agricultural season category.')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.translations.hi.name', 'रबी')
        ->assertJsonPath('data.created_at', '2026-11-05T05:00:00.123456Z')
        ->assertJsonPath('data.added_at', '5 Nov 2026 05:00 AM')
        ->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data.translations.en')))->toBe(['locale', 'name', 'description']);
    $season = Season::query()->sole();
    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'slug' => 'rabi', 'is_active' => false]);
    $this->assertDatabaseCount('season_translations', 2);
    expect($season->translations->firstWhere('locale', 'en')->season->id)->toBe($season->id);
});

test('admins can create an additional season and any additional configured language', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $payload = seasonPayload(['slug' => 'regional-season']);
    $payload['translations']['gu'] = ['name' => 'રબી', 'description' => ''];

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/seasons', $payload)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'regional-season')
        ->assertJsonPath('data.translations.gu.name', 'રબી')
        ->assertJsonPath('data.translations.gu.description', null);

    $this->assertDatabaseCount('season_translations', 3);
    $this->assertDatabaseHas('season_translations', ['locale' => 'gu', 'name' => 'રબી']);
});

test('invalid season creation returns 422 without saving master or translations', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/seasons', seasonPayload($changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('seasons', 0);
    $this->assertDatabaseCount('season_translations', 0);
})->with([
    'missing slug' => [['slug' => null], 'slug'],
    'uppercase slug' => [['slug' => 'Rabi'], 'slug'],
    'space in slug' => [['slug' => 'winter season'], 'slug'],
    'double hyphen slug' => [['slug' => 'winter--season'], 'slug'],
    'long slug' => [['slug' => str_repeat('a', 256)], 'slug'],
    'missing English' => [['translations' => ['hi' => ['name' => 'रबी']]], 'translations.en.name'],
    'missing Hindi' => [['translations' => ['en' => ['name' => 'Rabi']]], 'translations.hi.name'],
    'blank English' => [['translations' => ['en' => ['name' => ' '], 'hi' => ['name' => 'रबी']]], 'translations.en.name'],
    'long name' => [['translations' => ['en' => ['name' => str_repeat('a', 256)], 'hi' => ['name' => 'रबी']]], 'translations.en.name'],
    'non-string name' => [['translations' => ['en' => ['name' => 123], 'hi' => ['name' => 'रबी']]], 'translations.en.name'],
    'non-string description' => [['translations' => ['en' => ['name' => 'Rabi', 'description' => []], 'hi' => ['name' => 'रबी']]], 'translations.en.description'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'Rabi'], 'hi' => ['name' => 'रबी'], 'xx' => ['name' => 'Other']]], 'translations'],
    'numeric locale array' => [['translations' => [['locale' => 'en', 'name' => 'Rabi'], ['locale' => 'en', 'name' => 'Duplicate']]], 'translations'],
    'translation record ID' => [['translations' => ['en' => ['name' => 'Rabi', 'id' => 99], 'hi' => ['name' => 'रबी']]], 'translations.en'],
    'translation reparenting' => [['translations' => ['en' => ['name' => 'Rabi', 'season_id' => 99], 'hi' => ['name' => 'रबी']]], 'translations.en'],
    'review field' => [['review_status' => 'approved'], 'review_status'],
    'master ID' => [['id' => 99], 'id'],
    'timestamp' => [['created_at' => '2026-01-01'], 'created_at'],
    'activation bypass' => [['is_active' => true], 'is_active'],
    'unexpected request locale' => [['locale' => 'en'], 'locale'],
]);

test('required season name messages identify English and Hindi fields', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/seasons', ['slug' => 'rabi', 'translations' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['translations.en.name', 'translations.hi.name']);

    expect($response->json('errors')['translations.en.name'][0])->toBe('The English season name is required.');
    expect($response->json('errors')['translations.hi.name'][0])->toBe('The Hindi season name is required.');
});

test('season slugs remain globally unique during creation and updates', function () {
    $admin = AdminUser::factory()->create();
    Season::factory()->withNames()->create(['slug' => 'rabi']);
    $other = Season::factory()->withNames('Kharif', 'खरीफ')->create(['slug' => 'kharif']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->postJson('/api/admin/seasons', seasonPayload())
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->withToken($token)->patchJson('/api/admin/seasons/'.$other->id, ['slug' => 'rabi'])
        ->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->withToken($token)->patchJson('/api/admin/seasons/'.$other->id, ['slug' => 'kharif'])
        ->assertOk()->assertJsonPath('data.slug', 'kharif');

    $this->assertDatabaseHas('seasons', ['id' => $other->id, 'slug' => 'kharif']);
    $this->assertDatabaseCount('seasons', 2);
});

test('updating a language preserves omitted locales descriptions and the stable slug', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create(['slug' => 'rabi']);
    $season->translations()->where('locale', 'en')->update(['description' => 'Preserved description.']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id, ['translations' => ['en' => ['name' => 'Updated season']]])
        ->assertOk()->assertJsonPath('data.slug', 'rabi')
        ->assertJsonPath('data.translations.en.name', 'Updated season')
        ->assertJsonPath('data.translations.en.description', 'Preserved description.')
        ->assertJsonPath('data.translations.hi.name', 'रबी');

    $this->assertDatabaseCount('season_translations', 2);
    $this->assertDatabaseHas('season_translations', ['season_id' => $season->id, 'locale' => 'en', 'name' => 'Updated season']);
});

test('description-only updates preserve names and explicitly clear descriptions', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    $season->translations()->where('locale', 'en')->update(['description' => 'Old description.']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id, ['translations' => ['en' => ['description' => '']]])
        ->assertOk()->assertJsonPath('data.translations.en.name', 'Rabi')
        ->assertJsonPath('data.translations.en.description', null);

    $this->assertDatabaseHas('season_translations', ['season_id' => $season->id, 'locale' => 'en', 'name' => 'Rabi', 'description' => null]);
});

test('partial updates add a configured translation while preserving existing languages', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id, ['translations' => ['gu' => ['name' => 'રબી']]])
        ->assertOk()->assertJsonPath('data.translations.gu.name', 'રબી')
        ->assertJsonPath('data.translations.en.name', 'Rabi');

    $this->assertDatabaseCount('season_translations', 3);
});

test('invalid partial updates return 422 and preserve required translations', function (array $changes, string $field) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create(['slug' => 'rabi']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'slug' => 'rabi', 'is_active' => false]);
    $this->assertDatabaseHas('season_translations', ['season_id' => $season->id, 'locale' => 'en', 'name' => 'Rabi']);
    $this->assertDatabaseHas('season_translations', ['season_id' => $season->id, 'locale' => 'hi', 'name' => 'रबी']);
    $this->assertDatabaseCount('season_translations', 2);
})->with([
    'empty translations' => [['translations' => []], 'translations'],
    'null translations' => [['translations' => null], 'translations'],
    'remove English' => [['translations' => ['en' => null]], 'translations.en'],
    'blank Hindi' => [['translations' => ['hi' => ['name' => ' ']]], 'translations.hi.name'],
    'new language without name' => [['translations' => ['gu' => ['description' => 'New']]], 'translations.gu.name'],
    'unsupported locale' => [['translations' => ['xx' => ['name' => 'Unknown']]], 'translations'],
    'reparent translation' => [['translations' => ['en' => ['name' => 'Changed', 'season_id' => 99]]], 'translations.en'],
    'activation bypass' => [['is_active' => true], 'is_active'],
    'protected timestamp' => [['updated_at' => '2026-01-01'], 'updated_at'],
    'unexpected request locale' => [['locale' => 'en'], 'locale'],
]);

test('admins activate complete seasons and deactivate them without losing translations', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/seasons/'.$season->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'is_active' => true]);

    $this->withToken($token)->patchJson('/api/admin/seasons/'.$season->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.translations.hi.name', 'रबी');

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'is_active' => false]);
    $this->assertDatabaseCount('season_translations', 2);
});

test('activation returns 422 when a required season translation is missing or blank', function (string $locale, ?string $name) {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    $season->translations()->where('locale', $locale)->delete();
    if ($name !== null) {
        $season->translations()->create(['locale' => $locale, 'name' => $name]);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.'.$locale.'.name');

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'is_active' => false]);
})->with([
    'missing English' => ['en', null],
    'missing Hindi' => ['hi', null],
    'blank Hindi' => ['hi', '  '],
]);

test('status requests reject invalid or unrelated fields with 422', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/seasons/'.$season->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'slug' => $season->slug, 'is_active' => false]);
})->with([
    'missing status' => [[], 'is_active'],
    'invalid status' => [['is_active' => 'yes'], 'is_active'],
    'unrelated slug' => [['is_active' => true, 'slug' => 'changed'], 'slug'],
    'unrelated translations' => [['is_active' => true, 'translations' => ['en' => ['name' => 'Changed']]], 'translations'],
    'unexpected request locale' => [['is_active' => true, 'locale' => 'en'], 'locale'],
]);

test('season listings search resolved names slugs and literal wildcard characters', function (string $keyword, string $locale) {
    $admin = AdminUser::factory()->create();
    $match = Season::factory()->withNames('Rabi 50%_!', 'रबी')->create(['slug' => 'winter-catalogue']);
    Season::factory()->withNames('Other 500abc!', 'अन्य')->create(['slug' => 'other']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/seasons?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'English name' => ['Rabi 50', 'en'],
    'Hindi name' => ['रबी', 'hi'],
    'slug' => ['winter-catalogue', 'en'],
    'literal wildcards' => ['50%_!', 'en'],
]);

test('season listings paginate by English name and ID while preserving allowed filters', function () {
    $admin = AdminUser::factory()->create();
    Season::factory()->withNames('Zaid', 'अ')->create(['is_active' => true]);
    $first = Season::factory()->withNames('Kharif', 'ज')->create(['is_active' => true]);
    $second = Season::factory()->withNames('Kharif', 'क')->create(['is_active' => true]);
    Season::factory()->withNames('Autumn', 'अन्य')->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $query = ['status' => 1, 'locale' => 'hi', 'per_page' => 1];

    $response = $this->withToken($token)
        ->getJson('/api/admin/seasons?'.http_build_query([...$query, 'ignored' => 'discard']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.name', 'Kharif')->assertJsonMissingPath('data.0.translations')
        ->assertJsonPath('pagination.current_page', 1)->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.last_page', 3);

    expect($response->json('pagination.next_page_url'))->toContain('status=1', 'per_page=1')->not->toContain('ignored=', 'locale=');
    expect(array_keys($response->json('pagination')))->toBe([
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'next_page_url', 'prev_page_url',
    ]);

    $this->withToken($token)->getJson('/api/admin/seasons?'.http_build_query([...$query, 'page' => 2]))
        ->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->withToken($token)->getJson('/api/admin/seasons?status=0')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Autumn');
});

test('season pagination defaults to fifteen and empty pages have nullable bounds', function () {
    $admin = AdminUser::factory()->create();
    Season::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/seasons')
        ->assertOk()->assertJsonPath('pagination.per_page', 15);
    $this->withToken($token)->getJson('/api/admin/seasons?page=2&per_page=1')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('season list inputs return 422 for invalid filters', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/seasons?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page' => [['page' => 0], 'page'],
    'zero per page' => [['per_page' => 0], 'per_page'],
    'too many per page' => [['per_page' => 101], 'per_page'],
    'fractional page' => [['page' => 1.5], 'page'],
    'invalid status' => [['status' => 'active'], 'status'],
    'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'],
]);

test('season details resolve header locales and retain every edit translation', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    $season->translations()->where('locale', 'hi')->update(['description' => 'हिन्दी विवरण']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seasons/'.$season->id)
        ->assertOk()->assertJsonPath('data.name', 'रबी')->assertJsonPath('data.description', 'हिन्दी विवरण')
        ->assertJsonPath('data.translations.en.name', 'Rabi')->assertJsonPath('data.translations.hi.name', 'रबी');

    $this->withToken($token)->withHeader('X-Locale', 'gu')->getJson('/api/admin/seasons/'.$season->id)
        ->assertOk()->assertJsonPath('data.name', 'Rabi');
});

test('locale headers and English fallback also control season search and rendering', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->create();
    SeasonTranslation::factory()->for($season)->create(['locale' => 'en', 'name' => 'English only', 'description' => 'Fallback description.']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->withHeader('X-Locale', 'hi')->getJson('/api/admin/seasons?keyword=English')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'English only')
        ->assertJsonPath('data.0.description', 'Fallback description.');
});

test('season requests use the established Accept-Language header when no locale override exists', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('Accept-Language', 'hi')
        ->getJson('/api/admin/seasons/'.$season->id)
        ->assertOk()->assertJsonPath('data.name', 'रबी');
});

test('missing seasons return 404 with the standard envelope even without an Accept header', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->get('/api/admin/seasons/999')->assertNotFound()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

test('season read endpoints ignore locale query parameters and use the middleware locale', function (mixed $queryLocale) {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();

    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $query = http_build_query(['locale' => $queryLocale]);

    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seasons/'.$season->id.'?'.$query)
        ->assertOk()->assertJsonPath('data.name', 'रबी');
    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seasons?'.$query)
        ->assertOk()->assertJsonPath('data.0.name', 'रबी');
})->with([
    'supported locale' => ['en'],
    'unsupported locale' => ['xx'],
    'array locale' => [['hi']],
]);

test('season listings order by the localized name with English fallback and stable IDs', function () {
    $admin = AdminUser::factory()->create();
    $translated = Season::factory()->withNames('Alpha', 'Zulu')->create();
    $fallback = Season::factory()->create();
    SeasonTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'Beta']);
    $sameName = Season::factory()->withNames('Gamma', 'Beta')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seasons')
        ->assertOk()->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('data.0.name', 'Beta')->assertJsonPath('data.1.name', 'Beta')
        ->assertJsonPath('data.2.name', 'Zulu');

    expect(array_column($response->json('data'), 'id'))->toBe([$fallback->id, $sameName->id, $translated->id]);
});

test('season lists reuse localized query fields without another translation query', function () {
    $admin = AdminUser::factory()->create();
    Season::factory()->count(4)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->getJson('/api/admin/seasons')
            ->assertOk()->assertJsonCount(4, 'data');

        $translationQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'select * from "season_translations"'),
        );
        expect($translationQueries)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('season resources never query unloaded translation relationships', function () {
    $season = Season::factory()->make(['is_active' => false]);
    DB::enableQueryLog();

    try {
        $data = (new SeasonResource($season))->resolve(Request::create('/'));

        expect($data['name'])->toBeNull();
        expect($data['description'])->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('new selection scopes exclude inactive seasons without hiding historical lookup names', function () {
    $active = Season::factory()->withNames('Kharif', 'खरीफ')->create(['is_active' => true]);
    $inactive = Season::factory()->withNames()->create();

    expect(Season::query()->published()->pluck('seasons.id')->all())->toBe([$active->id]);
    expect(Season::query()->with('translations')->findOrFail($inactive->id)->translatedName())->toBe('Rabi');
});

test('deactivation preserves referenced farming history and deletion returns 409', function (string $deleteAction) {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    Schema::create('season_history_records', function (Blueprint $table) use ($deleteAction): void {
        $table->id();
        $table->unsignedBigInteger('season_id')->nullable();
        $table->date('sowing_date');
        $table->foreign('season_id')->references('id')->on('seasons')->onDelete($deleteAction);
    });
    DB::table('season_history_records')->insert(['season_id' => $season->id, 'sowing_date' => '2025-10-15']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    try {
        $this->withToken($token)->patchJson('/api/admin/seasons/'.$season->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->withToken($token)->deleteJson('/api/admin/seasons/'.$season->id)->assertConflict()
            ->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'The season cannot be deleted while active or referenced by other records. Deactivate it instead.',
            ]);

        $this->withToken($token)->getJson('/api/admin/seasons/'.$season->id)
            ->assertOk()->assertJsonPath('data.name', 'Rabi');
        $this->assertDatabaseHas('season_history_records', ['season_id' => $season->id, 'sowing_date' => '2025-10-15']);
        $this->assertDatabaseHas('seasons', ['id' => $season->id, 'is_active' => false]);
        $this->assertDatabaseCount('season_translations', 2);
    } finally {
        Schema::dropIfExists('season_history_records');
    }
})->with(['restrict' => ['restrict'], 'cascade' => ['cascade'], 'set null' => ['set null']]);

test('active seasons return 409 on deletion following catalogue conventions', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create(['is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/seasons/'.$season->id)->assertConflict();

    $this->assertModelExists($season);
    $this->assertDatabaseCount('season_translations', 2);
});

test('unreferenced inactive seasons can be deleted along with only their own translations', function () {
    $admin = AdminUser::factory()->create();
    $season = Season::factory()->withNames()->create();
    $other = Season::factory()->withNames('Zaid', 'जायद')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/seasons/'.$season->id)
        ->assertOk()->assertExactJson([
            'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Season deleted successfully.',
        ]);

    $this->assertModelMissing($season);
    $this->assertDatabaseMissing('season_translations', ['season_id' => $season->id]);
    $this->assertModelExists($other);
    $this->assertDatabaseCount('season_translations', 2);
});

test('database constraints reject duplicate slugs duplicate locales and orphan translations', function () {
    $season = Season::factory()->withNames()->create(['slug' => 'rabi']);

    expect(fn () => Season::factory()->create(['slug' => 'rabi']))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $season->translations()->create(['locale' => 'en', 'name' => 'Duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => SeasonTranslation::factory()->create(['season_id' => 999]))
        ->toThrow(QueryException::class);

    $this->assertDatabaseCount('seasons', 1);
    $this->assertDatabaseCount('season_translations', 2);
});

test('failed translation persistence rolls back the season and every supplied translation', function () {
    $admin = AdminUser::factory()->create();
    SeasonTranslation::creating(function (SeasonTranslation $translation): void {
        if ($translation->locale === 'hi') {
            $translation->name = null;
        }
    });

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/seasons', seasonPayload())
        ->assertInternalServerError()
        ->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null,
            'message' => 'Unable to create the season. Please try again later.',
        ]);

    $this->assertDatabaseCount('seasons', 0);
    $this->assertDatabaseCount('season_translations', 0);
});

test('season resources render localized query fields without loading translation relationships', function (string $locale, ?string $hindiDescription, string $expectedName, ?string $expectedDescription) {
    config()->set('languages.supported.gu', 'Gujarati');
    $season = Season::factory()->withNames('Rabi', 'रबी')->create();
    $season->translations()->where('locale', 'en')->update(['description' => 'English description.']);
    $season->translations()->where('locale', 'hi')->update(['description' => $hindiDescription]);
    app()->setLocale($locale);
    $localized = Season::query()->withLocalizedName()->findOrFail($season->id);
    DB::enableQueryLog();

    try {
        $data = (new SeasonResource($localized))->resolve(Request::create('/api/admin/seasons'));

        expect($data['name'])->toBe($expectedName);
        expect($data['description'])->toBe($expectedDescription);
        expect($localized->relationLoaded('translations'))->toBeFalse();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with([
    'English' => ['en', 'हिन्दी विवरण', 'Rabi', 'English description.'],
    'Hindi' => ['hi', 'हिन्दी विवरण', 'रबी', 'हिन्दी विवरण'],
    'missing Gujarati falls back to English' => ['gu', 'हिन्दी विवरण', 'Rabi', 'English description.'],
    'cleared Hindi description stays null' => ['hi', null, 'रबी', null],
]);

test('season listings resolve locale headers for names and descriptions with per-record fallback', function (array $headers, string $expectedName, string $expectedDescription) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $translated = Season::factory()->withNames('Rabi', 'रबी')->create();
    $translated->translations()->where('locale', 'en')->update(['description' => 'English description.']);
    $translated->translations()->where('locale', 'hi')->update(['description' => 'हिन्दी विवरण']);
    $translated->translations()->create(['locale' => 'gu', 'name' => 'રબી', 'description' => 'ગુજરાતી વર્ણન']);
    $fallback = Season::factory()->create();
    SeasonTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'Fallback season', 'description' => 'Fallback description.']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/seasons?locale=en')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$translated->id]['name'])->toBe($expectedName);
    expect($rows[$translated->id]['description'])->toBe($expectedDescription);
    expect($rows[$fallback->id]['name'])->toBe('Fallback season');
    expect($rows[$fallback->id]['description'])->toBe('Fallback description.');
})->with([
    'default English' => [[], 'Rabi', 'English description.'],
    'Hindi locale header' => [['X-Locale' => 'hi'], 'रबी', 'हिन्दी विवरण'],
    'Hindi language header' => [['Accept-Language' => 'hi'], 'रबी', 'हिन्दी विवरण'],
    'configured Gujarati' => [['X-Locale' => 'gu'], 'રબી', 'ગુજરાતી વર્ણન'],
    'unsupported header falls back to English' => [['X-Locale' => 'xx'], 'Rabi', 'English description.'],
]);

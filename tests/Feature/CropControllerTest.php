<?php

use App\Http\Resources\Admin\CropResource;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropCategory;
use App\Models\CropTranslation;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function cropPayload(CropCategory $category, array $overrides = []): array
{
    return array_replace([
        'category_id' => $category->id,
        'slug' => 'wheat',
        'scientific_name' => 'Triticum aestivum',
        'crop_code' => 'WHEAT',
        'translations' => [
            'en' => ['name' => 'Wheat', 'description' => 'A cereal crop.'],
            'hi' => ['name' => 'गेहूं', 'description' => 'अनाज की फसल।'],
        ],
    ], $overrides);
}

test('crop endpoints require an admin bearer token', function (string $method, string $suffix) {
    $crop = Crop::factory()->withNames()->create();

    $this->json($method, '/api/admin/crops'.str_replace('{id}', $crop->id, $suffix))
        ->assertUnauthorized()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
        ]);

    $this->assertModelExists($crop);
})->with([
    'index' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('frontend identities cannot access crop administration even with wildcard abilities', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)->getJson('/api/admin/crops')->assertUnauthorized();
});

test('inactive admins and tokens missing admin ability cannot access crops', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)->getJson('/api/admin/crops')->assertForbidden();
})->with(['inactive admin' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('crop management and AI gates have separate permission boundaries', function () {
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-crops'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->make())->allows('manage-crop-ai-diagnosis'))->toBeFalse();
    expect(Gate::forUser(AdminUser::factory()->superUser()->make())->allows('manage-crop-ai-diagnosis'))->toBeTrue();
    expect(Gate::forUser(AdminUser::factory()->superUser()->inactive()->make())->allows('manage-crops'))->toBeFalse();
    expect(Gate::forUser(User::factory()->make())->allows('manage-crop-ai-diagnosis'))->toBeFalse();
});

test('every crop endpoint enforces the management gate', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $crop = Crop::factory()->withNames()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crops'.str_replace('{id}', $crop->id, $suffix))->assertForbidden();

    $this->assertModelExists($crop);
})->with([
    'index' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('admins create inactive crops with English Hindi and publication metadata', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->withEnglishName('Cereals')->create();
    $this->travelTo('2026-11-04 09:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category));

    $response->assertCreated()->assertJsonPath('data.category_id', $category->id)->assertJsonPath('data.category_name', 'Cereals')
        ->assertJsonPath('data.slug', 'wheat')->assertJsonPath('data.name', 'Wheat')
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.ai_diagnosis_enabled', false)
        ->assertJsonPath('data.image_url', null)->assertJsonPath('data.scientific_name', 'Triticum aestivum')
        ->assertJsonPath('data.crop_code', 'WHEAT')->assertJsonPath('data.translations.hi.name', 'गेहूं')
        ->assertJsonPath('data.added_at', '4 Nov 2026 09:00 AM')->assertJsonPath('data.created_at', '2026-11-04T09:00:00.123456Z')
        ->assertJsonPath('pagination', null)->assertJsonMissingPath('data.image');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data.translations.en')))->toBe(['locale', 'name', 'description']);
    $crop = Crop::query()->sole();
    expect($category->crops->sole()->id)->toBe($crop->id);
    expect($crop->category->id)->toBe($category->id);
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'category_id' => $category->id, 'is_active' => false, 'ai_diagnosis_enabled' => false]);
    $this->assertDatabaseCount('crop_translations', 2);
    expect($crop->translations->first()->crop->id)->toBe($crop->id);
});

test('omitted slugs are generated from English names', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category, ['slug' => null]))
        ->assertCreated()->assertJsonPath('data.slug', 'wheat');

    $this->assertDatabaseHas('crops', ['slug' => 'wheat']);
});

test('invalid creation payloads return field errors without persisting crops', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertDatabaseCount('crops', 0);
    $this->assertDatabaseCount('crop_translations', 0);
})->with([
    'missing English' => [['translations' => ['hi' => ['name' => 'गेहूं']]], 'translations.en.name'],
    'missing Hindi' => [['translations' => ['en' => ['name' => 'Wheat']]], 'translations.hi.name'],
    'blank Hindi' => [['translations' => ['en' => ['name' => 'Wheat'], 'hi' => ['name' => ' ']]], 'translations.hi.name'],
    'invalid category' => [['category_id' => 999], 'category_id'],
    'invalid slug' => [['slug' => 'Invalid Slug'], 'slug'],
    'long scientific name' => [['scientific_name' => str_repeat('x', 256)], 'scientific_name'],
    'long code' => [['crop_code' => str_repeat('x', 51)], 'crop_code'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'Wheat'], 'hi' => ['name' => 'गेहूं'], 'xx' => ['name' => 'Other']]], 'translations'],
    'unexpected translation key' => [['translations' => ['en' => ['name' => 'Wheat', 'crop_id' => 999], 'hi' => ['name' => 'गेहूं']]], 'translations.en'],
    'long translated name' => [['translations' => ['en' => ['name' => str_repeat('x', 256)], 'hi' => ['name' => 'गेहूं']]], 'translations.en.name'],
    'invalid description' => [['translations' => ['en' => ['name' => 'Wheat', 'description' => ['invalid']], 'hi' => ['name' => 'गेहूं']]], 'translations.en.description'],
    'arbitrary image path' => [['image' => '/private/secret.jpg'], 'image'],
    'protected ID' => [['id' => 999], 'id'],
    'unknown field' => [['untrusted' => true], 'untrusted'],
    'invalid active flag' => [['is_active' => 'enabled'], 'is_active'],
]);

test('English and Hindi required messages use the expected nested field paths', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', ['category_id' => $category->id, 'slug' => 'wheat', 'translations' => []])
        ->assertUnprocessable()->assertJsonValidationErrors([
            'translations.en.name' => 'The English crop name is required.',
            'translations.hi.name' => 'The Hindi crop name is required.',
        ]);
});

test('crop creation accepts other configured locales', function () {
    config(['languages.supported.fr' => 'French']);
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    $payload = cropPayload($category);
    $payload['translations']['fr'] = ['name' => 'Blé'];

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', $payload)->assertCreated()->assertJsonPath('data.translations.fr.name', 'Blé');

    $this->assertDatabaseHas('crop_translations', ['locale' => 'fr', 'name' => 'Blé']);
});

test('unique slugs and codes are validated while allowing multiple null codes', function (array $changes, ?string $field) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    Crop::factory()->create(['slug' => 'existing', 'crop_code' => 'EXISTING']);
    Crop::factory()->create(['crop_code' => null]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category, $changes));

    if ($field !== null) {
        $response->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('crops', 2);
    } else {
        $response->assertCreated()->assertJsonPath('data.crop_code', null);
        $this->assertDatabaseCount('crops', 3);
    }
})->with([
    'duplicate slug' => [['slug' => 'existing'], 'slug'],
    'duplicate code' => [['crop_code' => 'EXISTING'], 'crop_code'],
    'optional code' => [['crop_code' => null], null],
]);

test('database constraints enforce crop slug code and translation locale uniqueness', function (string $kind) {
    $crop = Crop::factory()->withNames()->create(['slug' => 'wheat', 'crop_code' => 'WHEAT']);

    expect(fn () => match ($kind) {
        'slug' => Crop::factory()->create(['slug' => 'wheat']),
        'code' => Crop::factory()->create(['crop_code' => 'WHEAT']),
        'locale' => CropTranslation::factory()->for($crop)->create(['locale' => 'en']),
    })->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('crops', 1);
    $this->assertDatabaseCount('crop_translations', 2);
})->with(['slug', 'code', 'locale']);

test('partial updates preserve omitted translations descriptions slug and image', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['slug' => 'wheat', 'crop_code' => 'WHEAT', 'image' => 'crops/old.jpg']);
    $hindi = $crop->translations()->where('locale', 'hi')->firstOrFail();
    $hindi->update(['description' => 'Existing description']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, [
            'scientific_name' => 'Updated scientific name', 'crop_code' => 'WHEAT',
            'translations' => ['hi' => ['name' => 'नया गेहूं']],
        ])->assertOk()->assertJsonPath('data.slug', 'wheat')->assertJsonPath('data.translations.en.name', 'Wheat')
        ->assertJsonPath('data.translations.hi.name', 'नया गेहूं')
        ->assertJsonPath('data.translations.hi.description', 'Existing description');

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'slug' => 'wheat', 'crop_code' => 'WHEAT', 'image' => 'crops/old.jpg', 'scientific_name' => 'Updated scientific name']);
    $this->assertDatabaseCount('crop_translations', 2);
});

test('unchanged translations are not rewritten during metadata updates', function () {
    $admin = AdminUser::factory()->create();
    $this->travelTo('2026-11-04 09:00:00');
    $crop = Crop::factory()->withNames()->create();
    $english = $crop->translations()->where('locale', 'en')->firstOrFail();
    $this->travel(1)->hours();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['translations' => ['en' => ['name' => 'Wheat']]])->assertOk();

    expect($english->fresh()->updated_at->toISOString())->toBe('2026-11-04T09:00:00.000000Z');
});

test('invalid crop updates preserve existing metadata and translations', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create(['slug' => 'wheat', 'crop_code' => 'WHEAT']);
    Crop::factory()->create(['slug' => 'existing', 'crop_code' => 'EXISTING']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, $changes)->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'slug' => 'wheat', 'crop_code' => 'WHEAT']);
    $this->assertDatabaseCount('crop_translations', 2);
})->with([
    'duplicate slug' => [['slug' => 'existing'], 'slug'],
    'duplicate code' => [['crop_code' => 'EXISTING'], 'crop_code'],
    'missing category' => [['category_id' => 999], 'category_id'],
    'empty translations' => [['translations' => []], 'translations'],
    'delete Hindi' => [['translations' => ['hi' => null]], 'translations.hi'],
    'arbitrary image' => [['image' => 'crops/other.jpg'], 'image'],
    'unexpected nested ID' => [['translations' => ['hi' => ['name' => 'गेहूं', 'crop_id' => 999]]], 'translations.hi'],
]);

test('active crops cannot be created or moved under inactive categories', function (bool $existing) {
    $admin = AdminUser::factory()->create();
    $inactive = CropCategory::factory()->create();
    $crop = $existing ? Crop::factory()->published()->create() : null;

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($existing ? 'PATCH' : 'POST', '/api/admin/crops'.($existing ? '/'.$crop->id : ''),
            $existing ? ['category_id' => $inactive->id] : cropPayload($inactive, ['is_active' => true]));

    $response->assertUnprocessable()->assertJsonValidationErrors('category_id');
    if ($existing) {
        expect($crop->fresh()->category_id)->toBe($crop->category_id);
    } else {
        $this->assertDatabaseCount('crops', 0);
    }
})->with(['create' => [false], 'move' => [true]]);

test('validly active crops can be created under active categories', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category, ['is_active' => true]))
        ->assertCreated()->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('crops', ['category_id' => $category->id, 'is_active' => true]);
});

test('ordinary admins cannot enable or change AI flags', function (bool $existing) {
    config(['crops.ai_diagnosis_available' => true]);
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    $crop = $existing ? Crop::factory()->withNames()->create() : null;

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($existing ? 'PATCH' : 'POST', '/api/admin/crops'.($existing ? '/'.$crop->id : ''),
            $existing ? ['ai_diagnosis_enabled' => false] : cropPayload($category, ['ai_diagnosis_enabled' => true]))
        ->assertForbidden();
})->with(['create enabled' => [false], 'update flag' => [true]]);

test('AI flags require backend availability even for authorized admins', function () {
    config(['crops.ai_diagnosis_available' => false]);
    $admin = AdminUser::factory()->superUser()->create();
    $category = CropCategory::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category, ['ai_diagnosis_enabled' => true]))
        ->assertUnprocessable()->assertJsonValidationErrors(['ai_diagnosis_enabled' => 'AI diagnosis is not available.']);

    $this->assertDatabaseCount('crops', 0);
});

test('authorized admins can configure available AI flags and disable them', function () {
    config(['crops.ai_diagnosis_available' => true]);
    $admin = AdminUser::factory()->superUser()->create();
    $category = CropCategory::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $this->withToken($token)->postJson('/api/admin/crops', cropPayload($category, ['ai_diagnosis_enabled' => true]))
        ->assertCreated()->assertJsonPath('data.ai_diagnosis_enabled', true);
    $crop = Crop::query()->sole();

    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id, ['ai_diagnosis_enabled' => false])
        ->assertOk()->assertJsonPath('data.ai_diagnosis_enabled', false);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'ai_diagnosis_enabled' => false]);
});

test('failed translation persistence rolls back all crop writes', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    CropTranslation::creating(function (CropTranslation $translation): void {
        if ($translation->locale === 'hi') {
            throw new RuntimeException('Simulated persistence failure.');
        }
    });
    Exceptions::fake();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropPayload($category))->assertInternalServerError();

    $this->assertDatabaseCount('crops', 0);
    $this->assertDatabaseCount('crop_translations', 0);
    Exceptions::assertReported(RuntimeException::class);
});

test('crop search matches names slug scientific name and code with literal wildcard escaping', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $match = Crop::factory()->withNames('Grain 50%_!', 'गेहूं')->create([
        'slug' => 'catalogue-wheat', 'scientific_name' => 'Triticum aestivum', 'crop_code' => 'WHEAT-001',
    ]);
    Crop::factory()->withNames('Grain 500abc', 'अन्य')->create(['slug' => 'other', 'scientific_name' => 'Other', 'crop_code' => 'OTHER']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crops?'.http_build_query(['keyword' => $keyword, 'locale' => 'en']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('pagination.total', 1);
})->with(['English' => ['50%_!'], 'Hindi' => ['गेहूं'], 'slug' => ['catalogue-wheat'], 'scientific' => ['Triticum'], 'code' => ['WHEAT-001']]);

test('crop filters pagination and alphabetical English sorting do not duplicate rows', function () {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->published()->create();
    $first = Crop::factory()->withNames('Barley', 'जौ')->for($category, 'category')->create(['is_active' => true, 'ai_diagnosis_enabled' => true]);
    Crop::factory()->withNames('Wheat', 'गेहूं')->for($category, 'category')->create(['is_active' => true, 'ai_diagnosis_enabled' => true]);
    Crop::factory()->withNames('Another', 'अन्य')->for($category, 'category')->create();
    Crop::factory()->published('Maize')->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crops?'.http_build_query([
            'category_id' => $category->id, 'is_active' => 1, 'ai_diagnosis_enabled' => 1, 'locale' => 'en', 'per_page' => 1,
        ]));

    $response->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.name', 'जौ')
        ->assertJsonPath('data.0.category_name', 'Cereals')->assertJsonCount(1, 'data')
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.per_page', 1);
    expect($response->json('pagination.next_page_url'))->not->toContain('locale=');
    expect($response->json('pagination.next_page_url'))->toContain('is_active=1', 'ai_diagnosis_enabled=1', 'category_id='.$category->id);
});

test('equal English crop names use IDs for stable ordering and false status filters work', function () {
    $admin = AdminUser::factory()->create();
    $first = Crop::factory()->withNames()->create();
    $second = Crop::factory()->withNames()->create();
    Crop::factory()->published('Barley')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crops?is_active=0')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.1.id', $second->id);
});

test('invalid list filters return 422', function (string $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crops?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page' => ['page=0', 'page'], 'size' => ['per_page=101', 'per_page'],
    'category' => ['category_id=999', 'category_id'], 'active flag' => ['is_active=invalid', 'is_active'],
    'AI flag' => ['ai_diagnosis_enabled=invalid', 'ai_diagnosis_enabled'],
    'keyword type' => ['keyword[]=invalid', 'keyword'],
]);

test('empty crop pages preserve the response envelope and pagination contract', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crops')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.per_page', 15)->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('crop detail resources use eager loaded data and expose no storage path', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    Model::preventLazyLoading();

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->withHeader('X-Locale', 'hi')->getJson('/api/admin/crops/'.$crop->id.'?locale=en')->assertOk()
            ->assertJsonPath('data.translations.en.name', 'Wheat')->assertJsonPath('data.translations.hi.name', 'गेहूं')
                ->assertJsonPath('data.name', 'गेहूं')
                ->assertJsonMissingPath('data.image');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('status changes activate valid crops and preserve unrelated fields on deactivation', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->for(CropCategory::factory()->published(), 'category')->create(['crop_code' => 'WHEAT']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_active' => false, 'crop_code' => 'WHEAT']);
    $this->assertDatabaseCount('crop_translations', 2);
});

test('activation rejects inactive categories or missing required translations', function (string $condition, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->for(CropCategory::factory()->published(), 'category')->create();
    if ($condition === 'category') {
        $crop->category->is_active = false;
        $crop->category->save();
    } else {
        $crop->translations()->where('locale', $condition)->delete();
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_active' => false]);
})->with(['category' => ['category', 'category_id'], 'English' => ['en', 'translations.en.name'], 'Hindi' => ['hi', 'translations.hi.name']]);

test('publication eligibility excludes inactive crops inactive categories and incomplete names', function (string $condition) {
    $eligible = Crop::factory()->published()->create();
    $excluded = Crop::factory()->published('Barley')->create();
    if ($condition === 'crop') {
        $excluded->is_active = false;
        $excluded->save();
    } elseif ($condition === 'category') {
        $excluded->category->is_active = false;
        $excluded->category->save();
    } else {
        $excluded->translations()->where('locale', $condition)->delete();
    }

    app()->setLocale('hi');

    expect(Crop::query()->published()->get()->modelKeys())->toBe([$eligible->id]);
    expect(Crop::query()->findOrFail($excluded->id)->id)->toBe($excluded->id);
})->with(['crop', 'category', 'en', 'hi']);

test('future publication resources resolve locales with fallback without issuing extra queries', function () {
    $crop = Crop::factory()->published()->create();
    app()->setLocale('fr');
    $crop = Crop::query()->published()->sole();
    $request = Request::create('/api/future-crops');
    DB::enableQueryLog();
    DB::flushQueryLog();

    $data = (new CropResource($crop))->resolve($request);

    expect($data['name'])->toBe('Wheat');
    expect($data['category_name'])->toBe('Cereals');
    expect($data)->not->toHaveKeys(['translations', 'image']);
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('referenced categories cannot be deleted through API or database cascades', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-categories/'.$crop->category_id)->assertConflict();

    expect(fn () => $crop->category->delete())->toThrow(QueryException::class);
    $this->assertModelExists($crop);
    $this->assertModelExists($crop->category);
});

test('historical references prevent crop deletion and survive crop deactivation', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    Schema::create('retained_crop_records', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('crop_id')->constrained('crops')->restrictOnDelete();
        $table->string('recorded_name');
    });
    try {
        DB::table('retained_crop_records')->insert(['crop_id' => $crop->id, 'recorded_name' => 'Historical Wheat']);
        $token = $admin->createToken('test', ['admin'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id.'/status', ['is_active' => false])->assertOk();

        $this->withToken($token)->deleteJson('/api/admin/crops/'.$crop->id)->assertConflict();

        $this->withToken($token)->getJson('/api/admin/crops/'.$crop->id)->assertOk()->assertJsonPath('data.name', 'Wheat');
        $this->assertDatabaseHas('retained_crop_records', ['crop_id' => $crop->id, 'recorded_name' => 'Historical Wheat']);
        expect(Crop::query()->findOrFail($crop->id)->translations->where('locale', 'en')->sole()->name)->toBe('Wheat');
    } finally {
        Schema::dropIfExists('retained_crop_records');
    }
});

test('inactive unreferenced crops delete with their translations', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crops/'.$crop->id)->assertOk()->assertJsonPath('data', null);

    $this->assertModelMissing($crop);
    $this->assertDatabaseCount('crop_translations', 0);
});

test('active crops require deactivation before deletion', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crops/'.$crop->id)->assertConflict();

    $this->assertModelExists($crop);
});

test('missing crops return the standard 404 envelope', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/crops/999')
        ->assertNotFound()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

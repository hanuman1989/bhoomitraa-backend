<?php

use App\Http\Resources\Admin\CropStageResource;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropStage;
use App\Models\CropStageTranslation;
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
function cropStagePayload(Crop $crop, array $overrides = []): array
{
    return array_replace([
        'crop_id' => $crop->id,
        'code' => 'example-stage',
        'sequence' => 1,
        'translations' => [
            'en' => ['name' => 'Example stage', 'description' => 'Illustrative test content.'],
            'hi' => ['name' => 'उदाहरण अवस्था', 'description' => 'उदाहरण विवरण।'],
        ],
    ], $overrides);
}

test('growth stage endpoints return 401 without an admin bearer token', function (string $method, string $suffix) {
    $stage = CropStage::factory()->withNames()->create();

    $this->json($method, '/api/admin/crop-stages'.str_replace('{id}', (string) $stage->id, $suffix))
        ->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with([
    'index' => ['GET', ''],
    'store' => ['POST', ''],
    'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'],
    'destroy' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('frontend bearer identities cannot access growth stage administration', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/crop-stages')->assertUnauthorized();
});

test('growth stage administration returns 403 for inactive admins or missing abilities', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/crop-stages')->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('growth stage mutations require the crop management gate', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crop-stages'.str_replace('{id}', (string) $stage->id, $suffix), ['is_active' => true])
        ->assertForbidden();

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
    $this->assertDatabaseCount('crop_stages', 1);
    $this->assertDatabaseCount('crop_stage_translations', 2);
})->with([
    'create' => ['POST', ''],
    'update' => ['PATCH', '/{id}'],
    'delete' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('admins create an inactive growth stage with typed relationships and standard timestamps', function () {
    $admin = AdminUser::factory()->create(['is_super_user' => false]);
    $crop = Crop::factory()->withNames('Wheat', 'गेहूँ')->create();
    $this->travelTo('2026-11-05 08:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($crop))
        ->assertCreated()->assertJsonPath('data.crop_id', $crop->id)
        ->assertJsonPath('data.crop_name', 'Wheat')->assertJsonPath('data.code', 'example-stage')
        ->assertJsonPath('data.sequence', 1)->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.name', 'Example stage')->assertJsonPath('data.translations.hi.name', 'उदाहरण अवस्था')
        ->assertJsonPath('data.created_at', '2026-11-05T08:00:00.000000Z')
        ->assertJsonPath('data.added_at', '5 Nov 2026 08:00 AM')->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data')))->toBe([
        'id', 'crop_id', 'crop_name', 'code', 'sequence', 'is_active', 'name', 'translations', 'created_at', 'updated_at', 'added_at',
    ]);
    expect(array_keys($response->json('data.translations.en')))->toBe(['locale', 'name', 'description']);
    $stage = CropStage::query()->sole();
    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'crop_id' => $crop->id, 'code' => 'example-stage', 'sequence' => 1, 'is_active' => false]);
    $this->assertDatabaseCount('crop_stage_translations', 2);
    expect($crop->stages->sole()->id)->toBe($stage->id);
    expect($stage->crop->id)->toBe($crop->id);
    expect($stage->translations->firstWhere('locale', 'en')->cropStage->id)->toBe($stage->id);
    $this->getJson('/api/admin/crop-stages/'.$stage->id)
        ->assertOk()->assertJsonPath('data.created_at', '2026-11-05T08:00:00.000000Z');
});

test('growth stages accept additional configured locales and the maximum unsigned smallint sequence', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $payload = cropStagePayload($crop, ['sequence' => 65535]);
    $payload['translations']['gu'] = ['name' => 'ઉદાહરણ', 'description' => ''];

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', $payload)->assertCreated()
        ->assertJsonPath('data.sequence', 65535)->assertJsonPath('data.translations.gu.name', 'ઉદાહરણ')
        ->assertJsonPath('data.translations.gu.description', null);

    $this->assertDatabaseCount('crop_stage_translations', 3);
    $this->assertDatabaseHas('crop_stage_translations', ['locale' => 'gu', 'name' => 'ઉદાહરણ']);
});

test('invalid growth stage creation returns 422 without saving any records', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($crop, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('crop_stages', 0);
    $this->assertDatabaseCount('crop_stage_translations', 0);
})->with([
    'missing crop' => [['crop_id' => null], 'crop_id'],
    'nonexistent crop' => [['crop_id' => 999], 'crop_id'],
    'malformed crop' => [['crop_id' => ['unexpected']], 'crop_id'],
    'missing code' => [['code' => null], 'code'],
    'uppercase code' => [['code' => 'Flowering'], 'code'],
    'spaces in code' => [['code' => 'grain filling'], 'code'],
    'double hyphen code' => [['code' => 'grain--filling'], 'code'],
    'long code' => [['code' => str_repeat('a', 51)], 'code'],
    'missing sequence' => [['sequence' => null], 'sequence'],
    'zero sequence' => [['sequence' => 0], 'sequence'],
    'negative sequence' => [['sequence' => -1], 'sequence'],
    'overflow sequence' => [['sequence' => 65536], 'sequence'],
    'fractional sequence' => [['sequence' => 1.5], 'sequence'],
    'missing English' => [['translations' => ['hi' => ['name' => 'अवस्था']]], 'translations.en.name'],
    'missing Hindi' => [['translations' => ['en' => ['name' => 'Stage']]], 'translations.hi.name'],
    'blank Hindi' => [['translations' => ['en' => ['name' => 'Stage'], 'hi' => ['name' => ' ']]], 'translations.hi.name'],
    'long name' => [['translations' => ['en' => ['name' => str_repeat('a', 256)], 'hi' => ['name' => 'अवस्था']]], 'translations.en.name'],
    'nonstring name' => [['translations' => ['en' => ['name' => 1], 'hi' => ['name' => 'अवस्था']]], 'translations.en.name'],
    'invalid description' => [['translations' => ['en' => ['name' => 'Stage', 'description' => []], 'hi' => ['name' => 'अवस्था']]], 'translations.en.description'],
    'unsupported locale' => [['translations' => ['en' => ['name' => 'Stage'], 'hi' => ['name' => 'अवस्था'], 'xx' => ['name' => 'Other']]], 'translations'],
    'numeric locale list' => [['translations' => [['locale' => 'en', 'name' => 'Stage']]], 'translations'],
    'translation record ID' => [['translations' => ['en' => ['name' => 'Stage', 'id' => 99], 'hi' => ['name' => 'अवस्था']]], 'translations.en'],
    'reparent translation' => [['translations' => ['en' => ['name' => 'Stage', 'crop_stage_id' => 99], 'hi' => ['name' => 'अवस्था']]], 'translations.en'],
    'activation bypass' => [['is_active' => true], 'is_active'],
    'master ID' => [['id' => 99], 'id'],
    'timestamp' => [['created_at' => '2026-01-01'], 'created_at'],
    'review attribute' => [['review_status' => 'approved'], 'review_status'],
    'request locale' => [['locale' => 'en'], 'locale'],
    'unexpected slug' => [['slug' => 'stage'], 'slug'],
]);

test('growth stage name validation identifies the required language fields', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($crop, ['translations' => []]))
        ->assertUnprocessable()->assertJsonValidationErrors(['translations.en.name', 'translations.hi.name']);

    expect($response->json('errors')['translations.en.name'][0])->toBe('The English stage name is required.');
    expect($response->json('errors')['translations.hi.name'][0])->toBe('The Hindi stage name is required.');
});

test('growth stage code and sequence conflicts within a crop return 422', function (array $changes, string $field, string $message) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    CropStage::factory()->for($crop)->withNames()->create(['code' => 'used-code', 'sequence' => 3]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($crop, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($response->json('errors')[$field][0])->toBe($message);
    $this->assertDatabaseCount('crop_stages', 1);
})->with([
    'code' => [['code' => 'used-code'], 'code', 'The code is already used by another stage of this crop.'],
    'sequence' => [['sequence' => 3], 'sequence', 'The sequence is already used by another stage of this crop.'],
]);

test('different crops may reuse the same stage code and sequence', function () {
    $admin = AdminUser::factory()->create();
    CropStage::factory()->withNames()->create(['code' => 'example-stage', 'sequence' => 1]);
    $otherCrop = Crop::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($otherCrop))
        ->assertCreated()->assertJsonPath('data.crop_id', $otherCrop->id);

    $this->assertDatabaseCount('crop_stages', 2);
});

test('metadata updates preserve omitted translation fields status and stable stage code', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create(['code' => 'stable-code', 'sequence' => 1, 'is_active' => true]);
    $stage->translations()->where('locale', 'en')->update(['description' => 'Preserved.']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, [
            'sequence' => 4,
            'translations' => ['en' => ['name' => 'Updated title']],
        ])
        ->assertOk()->assertJsonPath('data.sequence', 4)->assertJsonPath('data.code', 'stable-code')
        ->assertJsonPath('data.is_active', true)->assertJsonPath('data.translations.en.name', 'Updated title')
        ->assertJsonPath('data.translations.en.description', 'Preserved.')
        ->assertJsonPath('data.translations.hi.name', 'उदाहरण अवस्था');

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'sequence' => 4, 'is_active' => true, 'code' => 'stable-code']);
    $this->assertDatabaseHas('crop_stage_translations', ['crop_stage_id' => $stage->id, 'locale' => 'en', 'name' => 'Updated title']);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('growth stage updates add configured languages and preserve names when clearing descriptions', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();
    $stage->translations()->where('locale', 'en')->update(['description' => 'Old description.']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, [
            'translations' => ['en' => ['description' => ''], 'gu' => ['name' => 'ઉદાહરણ']],
        ])
        ->assertOk()->assertJsonPath('data.translations.en.name', 'Example stage')
        ->assertJsonPath('data.translations.en.description', null)
        ->assertJsonPath('data.translations.gu.name', 'ઉદાહરણ')
        ->assertJsonPath('data.translations.hi.name', 'उदाहरण अवस्था');

    $this->assertDatabaseCount('crop_stage_translations', 3);
    $this->assertDatabaseHas('crop_stage_translations', ['crop_stage_id' => $stage->id, 'locale' => 'en', 'description' => null]);
});

test('invalid partial growth stage updates return 422 without changing the record', function (array $changes, string $field) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create(['code' => 'current', 'sequence' => 2]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'code' => 'current', 'sequence' => 2, 'is_active' => false]);
    $this->assertDatabaseHas('crop_stage_translations', ['crop_stage_id' => $stage->id, 'locale' => 'hi', 'name' => 'उदाहरण अवस्था']);
    $this->assertDatabaseCount('crop_stage_translations', 2);
})->with([
    'null English' => [['translations' => ['en' => null]], 'translations.en'],
    'blank English name' => [['translations' => ['en' => ['name' => ' ']]], 'translations.en.name'],
    'empty translations' => [['translations' => []], 'translations'],
    'new locale missing name' => [['translations' => ['gu' => ['description' => 'Description']]], 'translations.gu.name'],
    'unexpected locale' => [['translations' => ['xx' => ['name' => 'Other']]], 'translations'],
    'reparent translation' => [['translations' => ['en' => ['name' => 'Changed', 'crop_stage_id' => 99]]], 'translations.en'],
    'status bypass' => [['is_active' => true], 'is_active'],
    'sequence overflow' => [['sequence' => 65536], 'sequence'],
    'invalid code' => [['code' => 'UPPER'], 'code'],
    'request locale' => [['locale' => 'hi'], 'locale'],
]);

test('partial updates enforce code and sequence uniqueness using the existing parent crop', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    CropStage::factory()->for($crop)->create(['code' => 'used-code', 'sequence' => 3]);
    $stage = CropStage::factory()->for($crop)->withNames()->create(['code' => 'current-code', 'sequence' => 5]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'code' => 'current-code', 'sequence' => 5]);
})->with(['code' => [['code' => 'used-code'], 'code'], 'sequence' => [['sequence' => 3], 'sequence']]);

test('updates may retain current unique values and change codes without reordering other stages', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create(['code' => 'current-code', 'sequence' => 2]);
    $other = CropStage::factory()->for($crop)->create(['sequence' => 4]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, ['crop_id' => $crop->id, 'code' => 'changed-code', 'sequence' => 2])
        ->assertOk()->assertJsonPath('data.code', 'changed-code')->assertJsonPath('data.sequence', 2);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'code' => 'changed-code', 'sequence' => 2]);
    $this->assertDatabaseHas('crop_stages', ['id' => $other->id, 'sequence' => 4]);
});

test('growth stage parent crops are immutable even when the stage is unused', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();
    $otherCrop = Crop::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, ['crop_id' => $otherCrop->id])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');

    expect($response->json('errors')['crop_id'][0])->toBe('The parent crop cannot be changed after creating a growth stage.');
    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'crop_id' => $stage->crop_id]);
});

test('admins activate stages under active crops and can deactivate after the crop becomes inactive', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => true]);

    $crop->update(['is_active' => false]);
    expect(CropStage::query()->published()->count())->toBe(0);
    $this->withToken($token)->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.translations.hi.name', 'उदाहरण अवस्था');

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('activation under an inactive parent crop returns 422', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
});

test('activation returns 422 for missing or blank required stage names', function (string $locale, ?string $name) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create();
    $stage->translations()->where('locale', $locale)->delete();
    if ($name !== null) {
        $stage->translations()->create(['locale' => $locale, 'name' => $name]);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.'.$locale.'.name');

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
})->with(['English' => ['en', null], 'Hindi' => ['hi', null], 'blank Hindi' => ['hi', '  ']]);

test('activation rejects invalid legacy codes and sequence values with 422', function (array $attributes, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create($attributes);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
})->with([
    'invalid code' => [['code' => 'Invalid Code'], 'code'],
    'zero sequence' => [['sequence' => 0], 'sequence'],
    'overflow sequence' => [['sequence' => 65536], 'sequence'],
]);

test('active stage metadata cannot be saved under an inactive parent crop', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create(['code' => 'original', 'is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, ['code' => 'changed'])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'code' => 'original', 'is_active' => true]);
});

test('growth stage status requests reject invalid and unrelated attributes with 422', function (array $payload, string $field) {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false, 'code' => $stage->code]);
})->with([
    'missing status' => [[], 'is_active'],
    'invalid status' => [['is_active' => 'yes'], 'is_active'],
    'code mutation' => [['is_active' => true, 'code' => 'changed'], 'code'],
    'request locale' => [['is_active' => true, 'locale' => 'hi'], 'locale'],
    'translation mutation' => [['is_active' => true, 'translations' => ['en' => ['name' => 'Changed']]], 'translations'],
]);

test('growth stage lists search codes localized names and literal wildcards without duplicate masters', function (string $keyword, string $locale) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $match = CropStage::factory()->for($crop)->withNames('Example 50%_!', 'उदाहरण अवस्था')
        ->create(['code' => 'flowering-test', 'sequence' => 1]);
    CropStage::factory()->for($crop)->withNames('Other 500abc!', 'दूसरा')->create(['sequence' => 2]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/crop-stages?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'code' => ['flowering-test', 'en'],
    'English name' => ['Example', 'en'],
    'Hindi name' => ['उदाहरण अवस्था', 'hi'],
    'literal wildcard' => ['50%_!', 'en'],
]);

test('stage pagination orders by English crop name sequence and ID and retains allowed filters', function () {
    $admin = AdminUser::factory()->create();
    $wheat = Crop::factory()->withNames('Wheat', 'Alpha')->create();
    $barley = Crop::factory()->withNames('Barley', 'Zulu')->create();
    CropStage::factory()->for($wheat)->withNames()->create(['sequence' => 1, 'is_active' => true]);
    $later = CropStage::factory()->for($barley)->withNames()->create(['sequence' => 4, 'is_active' => true]);
    $first = CropStage::factory()->for($barley)->withNames()->create(['sequence' => 2, 'is_active' => true]);
    CropStage::factory()->for($barley)->withNames()->create(['sequence' => 3]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stages?status=1&per_page=1&locale=en&ignored=discard')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.0.crop_name', 'Barley')->assertJsonPath('data.0.name', 'उदाहरण अवस्था')
        ->assertJsonMissingPath('data.0.translations')->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('pagination.current_page', 1)->assertJsonPath('pagination.last_page', 3);

    expect($response->json('pagination.next_page_url'))->toContain('status=1', 'per_page=1')->not->toContain('locale=', 'ignored=');
    expect(array_keys($response->json('pagination')))->toBe([
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'next_page_url', 'prev_page_url',
    ]);

    $this->withToken($token)->getJson('/api/admin/crop-stages?status=1&per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $later->id);
    $this->withToken($token)->getJson('/api/admin/crop-stages?'.http_build_query(['crop_id' => $barley->id, 'status' => 0]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sequence', 3)
        ->assertJsonPath('pagination.total', 1);
});

test('crop filtered growth stage listings order primarily by sequence and preserve the crop filter in links', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $later = CropStage::factory()->for($crop)->withNames('Alpha', 'अ')->create(['sequence' => 5]);
    $first = CropStage::factory()->for($crop)->withNames('Zulu', 'ज')->create(['sequence' => 1]);
    CropStage::factory()->withNames()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-stages?'.http_build_query(['crop_id' => $crop->id, 'per_page' => 1]))
        ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('pagination.total', 2);

    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id);
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonPath('data.0.id', $later->id);
});

test('equal crop names and sequence values use stage IDs as a stable tie breaker', function () {
    $admin = AdminUser::factory()->create();
    $first = CropStage::factory()->for(Crop::factory()->withNames('Same', 'अ'))->withNames()->create();
    $second = CropStage::factory()->for(Crop::factory()->withNames('Same', 'ब'))->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-stages?per_page=1&page=2')
        ->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('pagination.total', 2);

    expect($first->id)->toBeLessThan($second->id);
});

test('stage pagination defaults to fifteen and empty pages have nullable bounds', function () {
    $admin = AdminUser::factory()->create();
    CropStage::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/crop-stages')->assertOk()->assertJsonPath('pagination.per_page', 15);
    $this->withToken($token)->getJson('/api/admin/crop-stages?page=2&per_page=1')
        ->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid growth stage list filters return 422', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-stages?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page' => [['page' => 0], 'page'],
    'fractional page' => [['page' => 1.5], 'page'],
    'zero per page' => [['per_page' => 0], 'per_page'],
    'maximum page size' => [['per_page' => 101], 'per_page'],
    'invalid crop' => [['crop_id' => 999], 'crop_id'],
    'invalid status' => [['status' => 'active'], 'status'],
    'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'],
]);

test('stage details use locale headers include all translations and keep crop names in English', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Wheat', 'गेहूँ')->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stages/'.$stage->id.'?locale=en')
        ->assertOk()->assertJsonPath('data.crop_name', 'Wheat')->assertJsonPath('data.name', 'उदाहरण अवस्था')
        ->assertJsonPath('data.translations.en.name', 'Example stage')
        ->assertJsonPath('data.translations.hi.name', 'उदाहरण अवस्था');
    $this->withToken($token)->withHeader('X-Locale', 'gu')->getJson('/api/admin/crop-stages/'.$stage->id)
        ->assertOk()->assertJsonPath('data.name', 'Example stage');
});

test('stage listings search and render English fallback when the requested translation is absent', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->create();
    CropStageTranslation::factory()->for($stage)->create(['locale' => 'en', 'name' => 'Fallback title']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('Accept-Language', 'hi')
        ->getJson('/api/admin/crop-stages?keyword=Fallback')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Fallback title');
});

test('stage listings use the middleware locale with English fallback for each record', function (array $headers, string $expectedName) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Wheat', 'गेहूँ')->create();
    $translated = CropStage::factory()->for($crop)->withNames('Example stage', 'उदाहरण अवस्था')->create(['sequence' => 1]);
    $translated->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ અવસ્થા']);
    $fallback = CropStage::factory()->for($crop)->create(['sequence' => 2]);
    CropStageTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'English fallback']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/crop-stages?crop_id='.$crop->id.'&locale=en')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $translated->id)
        ->assertJsonPath('data.0.name', $expectedName)->assertJsonPath('data.0.crop_name', 'Wheat')
        ->assertJsonPath('data.1.name', 'English fallback')->assertJsonPath('pagination.total', 2);
})->with([
    'default English' => [[], 'Example stage'],
    'Hindi locale header' => [['X-Locale' => 'hi'], 'उदाहरण अवस्था'],
    'Hindi language header' => [['Accept-Language' => 'hi'], 'उदाहरण अवस्था'],
    'additional configured locale' => [['X-Locale' => 'gu'], 'ઉદાહરણ અવસ્થા'],
    'unsupported header falls back to English' => [['X-Locale' => 'xx'], 'Example stage'],
]);

test('stage resources do not query unloaded relationships', function () {
    $stage = CropStage::factory()->make();
    DB::enableQueryLog();

    try {
        $data = (new CropStageResource($stage))->resolve(Request::create('/'));

        expect($data['name'])->toBeNull();
        expect($data)->not->toHaveKey('crop_name');
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('stage listings reuse localized query names and eager load crops once for the whole page', function () {
    $admin = AdminUser::factory()->create();
    CropStage::factory()->count(4)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->getJson('/api/admin/crop-stages')->assertOk()->assertJsonCount(4, 'data');

        foreach (['crop_translations', 'crops'] as $table) {
            $queries = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_starts_with($query['query'], 'select * from "'.$table.'"'),
            );
            expect($queries)->toHaveCount(1);
        }
        $stageTranslationQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_starts_with($query['query'], 'select * from "crop_stage_translations"'),
        );
        expect($stageTranslationQueries)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('missing stages return a safe 404 envelope without an Accept header', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->get('/api/admin/crop-stages/999')->assertNotFound()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

test('referenced stages preserve history on deactivation and return 409 on deletion', function (string $deleteAction) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create(['is_active' => true]);
    Schema::create('growth_stage_history_records', function (Blueprint $table) use ($deleteAction): void {
        $table->id();
        $table->unsignedBigInteger('crop_stage_id')->nullable();
        $table->date('recorded_on');
        $table->foreign('crop_stage_id')->references('id')->on('crop_stages')->onDelete($deleteAction);
    });
    DB::table('growth_stage_history_records')->insert(['crop_stage_id' => $stage->id, 'recorded_on' => '2025-10-15']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    try {
        $this->withToken($token)->patchJson('/api/admin/crop-stages/'.$stage->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->withToken($token)->deleteJson('/api/admin/crop-stages/'.$stage->id)
            ->assertConflict()->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'The growth stage cannot be deleted while active or referenced by other records. Deactivate it instead.',
            ]);
        $this->withToken($token)->getJson('/api/admin/crop-stages/'.$stage->id)
            ->assertOk()->assertJsonPath('data.name', 'Example stage');

        $this->assertDatabaseHas('growth_stage_history_records', ['crop_stage_id' => $stage->id, 'recorded_on' => '2025-10-15']);
        $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'is_active' => false]);
        $this->assertDatabaseCount('crop_stage_translations', 2);
    } finally {
        Schema::dropIfExists('growth_stage_history_records');
    }
})->with(['restrict' => ['restrict'], 'cascade' => ['cascade'], 'set null' => ['set null']]);

test('active stages must be deactivated before deletion following existing catalogue conventions', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create(['is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-stages/'.$stage->id)->assertConflict();

    $this->assertModelExists($stage);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('unreferenced inactive stages can be deleted with only their own translations', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create();
    $other = CropStage::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-stages/'.$stage->id)
        ->assertOk()->assertExactJson([
            'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Growth stage deleted successfully.',
        ]);

    $this->assertModelMissing($stage);
    $this->assertDatabaseMissing('crop_stage_translations', ['crop_stage_id' => $stage->id]);
    $this->assertModelExists($other);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('a crop with growth stages cannot be deleted through the existing crop endpoint', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $stage = CropStage::factory()->for($crop)->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crops/'.$crop->id)->assertConflict();

    $this->assertModelExists($crop);
    $this->assertModelExists($stage);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('published stage selections exclude inactive parents and stages while historical lookup remains available', function () {
    $crop = Crop::factory()->published()->create();
    $active = CropStage::factory()->for($crop)->withNames()->create(['is_active' => true, 'sequence' => 1]);
    $inactive = CropStage::factory()->for($crop)->withNames()->create(['sequence' => 2]);
    CropStage::factory()->withNames()->create(['is_active' => true]);

    expect(CropStage::query()->published()->pluck('crop_stages.id')->all())->toBe([$active->id]);
    expect(CropStage::query()->with('translations')->findOrFail($inactive->id)->translatedName())->toBe('Example stage');
});

test('stage listing totals count masters once when multiple language names match', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames('Shared title', 'Shared title')->create();
    CropStageTranslation::factory()->for($stage)->create(['locale' => 'gu', 'name' => 'Shared title']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stages?keyword=Shared')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1);
});

test('database constraints reject duplicate stage codes sequences locales and orphan translations', function () {
    $stage = CropStage::factory()->withNames()->create(['code' => 'used-code', 'sequence' => 3]);

    expect(fn () => CropStage::factory()->create(['crop_id' => $stage->crop_id, 'code' => 'used-code', 'sequence' => 4]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => CropStage::factory()->create(['crop_id' => $stage->crop_id, 'code' => 'other-code', 'sequence' => 3]))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $stage->translations()->create(['locale' => 'en', 'name' => 'Duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => CropStageTranslation::factory()->create(['crop_stage_id' => 999]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('crop_stages', 1);
    $this->assertDatabaseCount('crop_stage_translations', 2);
});

test('failed creation rolls back stage metadata and every supplied translation', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    CropStageTranslation::creating(function (CropStageTranslation $translation): void {
        if ($translation->locale === 'hi') {
            $translation->name = null;
        }
    });

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stages', cropStagePayload($crop))
        ->assertInternalServerError()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null,
            'message' => 'Unable to create the growth stage. Please try again later.',
        ]);

    $this->assertDatabaseCount('crop_stages', 0);
    $this->assertDatabaseCount('crop_stage_translations', 0);
});

test('failed updates roll back metadata and previously updated translations', function () {
    $admin = AdminUser::factory()->create();
    $stage = CropStage::factory()->withNames()->create(['code' => 'original', 'sequence' => 1]);
    CropStageTranslation::saving(function (CropStageTranslation $translation): void {
        if ($translation->locale === 'hi') {
            $translation->name = null;
        }
    });

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stages/'.$stage->id, [
            'code' => 'changed',
            'sequence' => 4,
            'translations' => ['en' => ['name' => 'Changed'], 'hi' => ['name' => 'बदला']],
        ])
        ->assertInternalServerError()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null,
            'message' => 'Unable to update the growth stage. Please try again later.',
        ]);

    $this->assertDatabaseHas('crop_stages', ['id' => $stage->id, 'code' => 'original', 'sequence' => 1]);
    $this->assertDatabaseHas('crop_stage_translations', ['crop_stage_id' => $stage->id, 'locale' => 'en', 'name' => 'Example stage']);
    $this->assertDatabaseHas('crop_stage_translations', ['crop_stage_id' => $stage->id, 'locale' => 'hi', 'name' => 'उदाहरण अवस्था']);
});

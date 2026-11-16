<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropCategory;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function cropRecommendatePayload(CropCategory $category, array $attributes = []): array
{
    return array_replace([
        'category_id' => $category->id,
        'slug' => 'wheat',
        'translations' => [
            'en' => ['name' => 'Wheat'],
            'hi' => ['name' => 'गेहूं'],
        ],
    ], $attributes);
}

test('crop creation stores the recommendation flag and defaults it to false', function (?bool $value, bool $expected) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();
    $attributes = $value === null ? [] : ['is_recommendate' => $value];

    $response = $this->withToken($admin->createToken('crop-recommendation', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropRecommendatePayload($category, $attributes))
        ->assertCreated()
        ->assertJsonPath('data.is_recommendate', $expected);

    $this->assertDatabaseHas('crops', [
        'id' => $response->json('data.id'),
        'is_recommendate' => $expected,
    ]);
})->with([
    'omitted defaults to false' => [null, false],
    'true is stored' => [true, true],
    'false is stored' => [false, false],
]);

test('crop updates accept and return the recommendation flag', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $token = $admin->createToken('crop-recommendation', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id, ['is_recommendate' => true])
        ->assertOk()->assertJsonPath('data.is_recommendate', true);
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_recommendate' => true]);

    $this->withToken($token)->patchJson('/api/admin/crops/'.$crop->id, ['is_recommendate' => false])
        ->assertOk()->assertJsonPath('data.is_recommendate', false);
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_recommendate' => false]);
});

test('invalid recommendation flag is rejected without changing the crop', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();

    $this->withToken($admin->createToken('crop-recommendation', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crops/'.$crop->id, ['is_recommendate' => 'enabled'])
        ->assertUnprocessable()->assertJsonValidationErrors('is_recommendate');

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_recommendate' => false]);
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'scientific_name' => null]);
});

test('crop listing can be filtered by recommendation flag', function () {
    $admin = AdminUser::factory()->create();
    Crop::factory()->withNames()->create(['is_recommendate' => false]);
    $recommendedCrop = Crop::factory()->withNames()->create(['is_recommendate' => true]);

    $this->withToken($admin->createToken('crop-recommendation', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crops?is_recommendate=1')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $recommendedCrop->id)
        ->assertJsonPath('data.0.is_recommendate', true)
        ->assertJsonPath('pagination.total', 1);
});

test('recommendation migration defaults existing crops and rolls back only its column', function () {
    $crop = Crop::factory()->withNames()->create();
    $before = $crop->fresh()->getRawOriginal();
    unset($before['is_recommendate']);
    $migration = require database_path('migrations/2026_11_16_094331_add_is_recommendate_to_crops_table.php');

    $migration->down();

    expect(Schema::hasColumn('crops', 'is_recommendate'))->toBeFalse();
    expect((array) DB::table('crops')->where('id', $crop->id)->first())->toBe($before);

    $migration->up();

    expect(Schema::hasColumn('crops', 'is_recommendate'))->toBeTrue();
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'is_recommendate' => false]);
    expect($crop->fresh()->getRawOriginal()['is_recommendate'])->toBe(0);
    expect($crop->translations()->count())->toBe(2);
});

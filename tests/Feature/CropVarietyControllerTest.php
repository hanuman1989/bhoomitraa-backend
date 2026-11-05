<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropTranslation;
use App\Models\CropVariety;
use App\Models\CropVarietyTranslation;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function cropVarietyPayload(Crop $crop, array $overrides = []): array
{
    return array_replace([
        'crop_id' => $crop->id,
        'slug' => 'hd-2967',
        'variety_code' => 'HD2967',
        'translations' => [
            'en' => [
                'name' => 'HD 2967',
                'description' => 'An improved wheat variety.',
                'region_notes' => 'Recommended for specified regions.',
            ],
            'hi' => [
                'name' => 'एचडी 2967',
                'description' => 'गेहूं की एक उन्नत किस्म।',
                'region_notes' => 'निर्दिष्ट क्षेत्रों के लिए उपयुक्त।',
            ],
        ],
    ], $overrides);
}

test('crop variety routes require an admin bearer token', function (string $method, string $suffix) {
    $variety = CropVariety::factory()->withNames()->create();

    $this->json($method, '/api/admin/crop-varieties'.str_replace('{id}', (string) $variety->id, $suffix))
        ->assertUnauthorized()
        ->assertExactJson([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'Unauthenticated.',
        ]);
})->with([
    'index' => ['GET', ''],
    'create' => ['POST', ''],
    'show' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'],
    'delete' => ['DELETE', '/{id}'],
    'status' => ['PATCH', '/{id}/status'],
]);

test('frontend identities cannot access crop variety administration', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/crop-varieties')
        ->assertUnauthorized();
});

test('crop variety management enforces the existing crop management gate', function () {
    $admin = AdminUser::factory()->create();
    $variety = CropVariety::factory()->withNames()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-varieties/'.$variety->id)
        ->assertForbidden();

    $this->assertModelExists($variety);
});

test('admins create an inactive variety with English and Hindi translations', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Wheat', 'गेहूं')->create();
    $this->travelTo('2026-11-04 09:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-varieties', cropVarietyPayload($crop, [
            'maturity_days_min' => 135,
            'maturity_days_max' => 150,
        ]));

    $response->assertCreated()
        ->assertJsonPath('data.crop_id', $crop->id)
        ->assertJsonPath('data.crop_name', 'Wheat')
        ->assertJsonPath('data.slug', 'hd-2967')
        ->assertJsonPath('data.variety_code', 'HD2967')
        ->assertJsonPath('data.maturity_days_min', 135)
        ->assertJsonPath('data.maturity_days_max', 150)
        ->assertJsonPath('data.source_id', null)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.name', 'HD 2967')
        ->assertJsonPath('data.translations.hi.region_notes', 'निर्दिष्ट क्षेत्रों के लिए उपयुक्त।')
        ->assertJsonPath('data.added_at', '4 Nov 2026 09:00 AM')
        ->assertJsonPath('data.created_at', '2026-11-04T09:00:00.123456Z')
        ->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('data.translations.en')))->toBe(['locale', 'name', 'description', 'region_notes']);
    $variety = CropVariety::query()->sole();
    $this->assertDatabaseHas('crop_varieties', [
        'id' => $variety->id,
        'crop_id' => $crop->id,
        'slug' => 'hd-2967',
        'maturity_days_min' => 135,
        'maturity_days_max' => 150,
        'is_active' => false,
    ]);
    $this->assertDatabaseCount('crop_variety_translations', 2);
    expect($crop->varieties->sole()->id)->toBe($variety->id);
    expect($variety->translations->first()->cropVariety->id)->toBe($variety->id);
});

test('invalid variety creation returns validation errors without writing', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-varieties', cropVarietyPayload($crop, $changes))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null);

    $this->assertDatabaseCount('crop_varieties', 0);
    $this->assertDatabaseCount('crop_variety_translations', 0);
})->with([
    'missing English' => [['translations' => ['hi' => ['name' => 'एचडी 2967']]], 'translations.en.name'],
    'missing Hindi' => [['translations' => ['en' => ['name' => 'HD 2967']]], 'translations.hi.name'],
    'invalid crop' => [['crop_id' => 999], 'crop_id'],
    'invalid slug' => [['slug' => 'HD 2967'], 'slug'],
    'invalid slug scope field' => [['slug' => str_repeat('a', 256)], 'slug'],
    'invalid maturity minimum' => [['maturity_days_min' => 0], 'maturity_days_min'],
    'invalid maturity range' => [['maturity_days_min' => 150, 'maturity_days_max' => 135], 'maturity_days_max'],
    'invalid source' => [['source_id' => 999], 'source_id'],
    'unsupported locale' => [[
        'translations' => [
            'en' => ['name' => 'HD 2967'],
            'hi' => ['name' => 'एचडी 2967'],
            'xx' => ['name' => 'Other'],
        ],
    ], 'translations'],
    'unexpected translation key' => [[
        'translations' => [
            'en' => ['name' => 'HD 2967', 'crop_id' => 999],
            'hi' => ['name' => 'एचडी 2967'],
        ],
    ], 'translations.en'],
    'unexpected attribute' => [['id' => 999], 'id'],
]);

test('variety slugs and codes are unique only within their parent crop', function () {
    $admin = AdminUser::factory()->create();
    $firstCrop = Crop::factory()->withNames('Wheat', 'गेहूं')->create();
    $secondCrop = Crop::factory()->withNames('Barley', 'जौ')->create();
    CropVariety::factory()->for($firstCrop)->withNames()->create([
        'slug' => 'shared-variety',
        'variety_code' => 'SHARED',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/admin/crop-varieties', cropVarietyPayload($secondCrop, [
            'slug' => 'shared-variety',
            'variety_code' => 'SHARED',
        ]))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/admin/crop-varieties', cropVarietyPayload($firstCrop, ['slug' => 'shared-variety']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');

    $this->assertDatabaseCount('crop_varieties', 2);
});

test('admins can activate varieties only under active crops with complete translations', function () {
    $admin = AdminUser::factory()->create();
    $activeCrop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($activeCrop)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id.'/status', ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    $inactiveCrop = Crop::factory()->create();
    $unpublished = CropVariety::factory()->for($inactiveCrop)->withNames()->create();
    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$unpublished->id.'/status', ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('crop_id');

    $this->assertDatabaseHas('crop_varieties', ['id' => $variety->id, 'is_active' => true]);
    $this->assertDatabaseHas('crop_varieties', ['id' => $unpublished->id, 'is_active' => false]);

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id, ['crop_id' => $inactiveCrop->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('crop_id');

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id.'/status', ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.translations.en.name', 'HD 2967');

    $this->assertDatabaseHas('crop_varieties', ['id' => $variety->id, 'is_active' => false]);
    $this->assertDatabaseHas('crop_variety_translations', ['crop_variety_id' => $variety->id, 'locale' => 'en']);
});

test('status activation rejects missing required names and unverified maturity sources', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $missingHindi = CropVariety::factory()->for($crop)->create();
    $missingHindi->translations()->create(['locale' => 'en', 'name' => 'English name']);
    $source = KnowledgeSource::factory()->create(['is_active' => true, 'verification_status' => 'pending']);
    $unverifiedSource = CropVariety::factory()->for($crop)->withNames()->create([
        'maturity_days_min' => 100,
        'maturity_days_max' => 120,
        'source_id' => $source->id,
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$missingHindi->id.'/status', ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('translations.hi.name');

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$unverifiedSource->id.'/status', ['is_active' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source_id');

    $this->assertDatabaseHas('crop_varieties', ['id' => $missingHindi->id, 'is_active' => false]);
    $this->assertDatabaseHas('crop_varieties', ['id' => $unverifiedSource->id, 'is_active' => false]);
});

test('active maturity information requires an active verified source', function (string $sourceState) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $source = $sourceState === 'active'
        ? KnowledgeSource::factory()->active()->create()
        : KnowledgeSource::factory()->create();
    $payload = cropVarietyPayload($crop, [
        'is_active' => true,
        'maturity_days_min' => 120,
        'maturity_days_max' => 135,
        'source_id' => $source->id,
    ]);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-varieties', $payload);

    if ($sourceState === 'active') {
        $response->assertCreated()
            ->assertJsonPath('data.source_id', $source->id)
            ->assertJsonPath('data.source_title', $source->title);
        $this->assertDatabaseHas('crop_varieties', ['source_id' => $source->id, 'is_active' => true]);
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('source_id');
        $this->assertDatabaseCount('crop_varieties', 0);
    }
})->with(['verified active' => ['active'], 'pending inactive' => ['pending']]);

test('admins update metadata and supplied translation fields without deleting omitted values', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create([
        'slug' => 'existing-variety',
        'variety_code' => 'EXISTING',
    ]);
    $variety->translations()->where('locale', 'en')->update([
        'description' => 'Preserved description.',
        'region_notes' => 'Preserved growing note.',
    ]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id, [
            'slug' => 'updated-variety',
            'translations' => ['en' => ['name' => 'Updated HD 2967']],
        ])
        ->assertOk()
        ->assertJsonPath('data.slug', 'updated-variety')
        ->assertJsonPath('data.translations.en.name', 'Updated HD 2967')
        ->assertJsonPath('data.translations.en.description', 'Preserved description.')
        ->assertJsonPath('data.translations.en.region_notes', 'Preserved growing note.')
        ->assertJsonPath('data.translations.hi.name', 'एचडी 2967');

    $this->assertDatabaseHas('crop_varieties', ['id' => $variety->id, 'slug' => 'updated-variety']);
    $this->assertDatabaseHas('crop_variety_translations', [
        'crop_variety_id' => $variety->id,
        'locale' => 'hi',
        'name' => 'एचडी 2967',
    ]);
    $this->assertDatabaseCount('crop_variety_translations', 2);
});

test('admins can update one translation field while preserving the other fields', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create();
    $english = $variety->translations()->where('locale', 'en')->firstOrFail();
    $english->update(['region_notes' => 'Preserved note.']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id, [
            'translations' => ['en' => ['description' => 'Updated description.']],
        ])
        ->assertOk()
        ->assertJsonPath('data.translations.en.name', 'HD 2967')
        ->assertJsonPath('data.translations.en.description', 'Updated description.')
        ->assertJsonPath('data.translations.en.region_notes', 'Preserved note.');

    $this->assertDatabaseHas('crop_variety_translations', [
        'id' => $english->id,
        'name' => 'HD 2967',
        'description' => 'Updated description.',
    ]);
});

test('admins can safely change a parent crop when no records reference the variety', function () {
    $admin = AdminUser::factory()->create();
    $originalCrop = Crop::factory()->withNames()->create();
    $newCrop = Crop::factory()->withNames()->create();
    $variety = CropVariety::factory()->for($originalCrop)->withNames()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id, ['crop_id' => $newCrop->id])
        ->assertOk()
        ->assertJsonPath('data.crop_id', $newCrop->id)
        ->assertJsonPath('data.crop_name', 'Wheat');

    $this->assertDatabaseHas('crop_varieties', ['id' => $variety->id, 'crop_id' => $newCrop->id]);
});

test('admins cannot change a slug to one already used by the same crop', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    CropVariety::factory()->for($crop)->withNames()->create(['slug' => 'already-used']);
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['slug' => 'current']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-varieties/'.$variety->id, ['slug' => 'already-used'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');

    $this->assertDatabaseHas('crop_varieties', ['id' => $variety->id, 'slug' => 'current']);
});

test('admins cannot reassign or delete a variety referenced by another table', function () {
    $admin = AdminUser::factory()->create();
    $originalCrop = Crop::factory()->withNames()->create();
    $newCrop = Crop::factory()->withNames()->create();
    $variety = CropVariety::factory()->for($originalCrop)->withNames()->create();
    Schema::create('variety_history_records', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('crop_variety_id');
        $table->foreign('crop_variety_id')->references('id')->on('crop_varieties')->restrictOnDelete();
    });
    DB::table('variety_history_records')->insert(['crop_variety_id' => $variety->id]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    try {
        $this->withToken($token)
            ->patchJson('/api/admin/crop-varieties/'.$variety->id, ['crop_id' => $newCrop->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('crop_id');

        $this->withToken($token)
            ->deleteJson('/api/admin/crop-varieties/'.$variety->id)
            ->assertStatus(409);

        $this->assertModelExists($variety);
        $this->assertDatabaseHas('variety_history_records', ['crop_variety_id' => $variety->id]);
    } finally {
        Schema::dropIfExists('variety_history_records');
    }
});

test('admins cannot delete an active variety but can delete an unreferenced inactive variety', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $active = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $inactive = CropVariety::factory()->for($crop)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)
        ->deleteJson('/api/admin/crop-varieties/'.$active->id)
        ->assertStatus(409);

    $this->withToken($token)
        ->deleteJson('/api/admin/crop-varieties/'.$inactive->id)
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null);

    $this->assertModelExists($active);
    $this->assertModelMissing($inactive);
    $this->assertDatabaseMissing('crop_variety_translations', ['crop_variety_id' => $inactive->id]);
});

test('variety listings search names slugs codes and parent crop names with literal wildcards', function (string $keyword, string $locale, string $name) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Wheat 50%_!', 'गेहूं')->create();
    $match = CropVariety::factory()->for($crop)->withNames('HD 50%_! grain', 'एचडी 2967')->create([
        'slug' => 'catalogue-hd',
        'variety_code' => 'HD-2967',
    ]);
    CropVariety::factory()->withNames('HD 500abc grain', 'अन्य')->create([
        'slug' => 'other',
        'variety_code' => 'OTHER',
    ]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/crop-varieties?'.http_build_query(['keyword' => $keyword, 'locale' => 'en']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('data.0.name', $name)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'English variety name' => ['50%_!', 'en', 'HD 50%_! grain'],
    'Hindi variety name' => ['एचडी 2967', 'hi', 'एचडी 2967'],
    'slug' => ['catalogue-hd', 'hi', 'एचडी 2967'],
    'code' => ['HD-2967', 'hi', 'एचडी 2967'],
    'English parent crop name' => ['Wheat 50%_!', 'en', 'HD 50%_! grain'],
]);

test('variety listings filter by crop and status and paginate in stable localized order', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    CropVariety::factory()->for($crop)->withNames('Barley', 'जौ')->create(['is_active' => true]);
    CropVariety::factory()->for($crop)->withNames('Durum', 'ड्यूरम')->create(['is_active' => true]);
    CropVariety::factory()->for($crop)->withNames('Emmer', 'खपली')->create(['is_active' => false]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?'.http_build_query([
            'crop_id' => $crop->id,
            'status' => 1,
            'locale' => 'en',
            'per_page' => 1,
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'जौ')
        ->assertJsonPath('data.0.crop_name', 'गेहूं')
        ->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.per_page', 1)
        ->assertJsonPath('pagination.total', 2);

    expect($response->json('pagination.next_page_url'))->not->toContain('locale=');
    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$crop->id);

    $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?'.http_build_query([
            'crop_id' => $crop->id,
            'status' => 1,
            'page' => 2,
            'per_page' => 1,
        ]))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'ड्यूरम');
});

test('variety listings fall back to English and return an empty page with nullable bounds', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    CropVariety::factory()->for($crop)
        ->has(CropVarietyTranslation::factory()->state(['locale' => 'en', 'name' => 'English only']), 'translations')
        ->create(['slug' => 'english-only']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?locale=hi&page=2&per_page=1')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('pagination.from', null)
        ->assertJsonPath('pagination.to', null)
        ->assertJsonPath('pagination.total', 1);
});

test('variety listings fall back to the configured default translation', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    CropVariety::factory()->for($crop)
        ->has(CropVarietyTranslation::factory()->state(['locale' => 'en', 'name' => 'English only']), 'translations')
        ->create(['slug' => 'english-only']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?locale=en')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'English only')
        ->assertJsonPath('data.0.crop_name', 'गेहूं');
});

test('variety pagination inputs are bounded', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-varieties?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'zero page' => [['page' => 0], 'page'],
    'too many per page' => [['per_page' => 101], 'per_page'],
]);

test('variety translations enforce one row per locale and maturity ranges are constrained', function () {
    $crop = Crop::factory()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create();

    expect(fn () => $variety->translations()->create(['locale' => 'en', 'name' => 'Duplicate']))
        ->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => DB::table('crop_varieties')->where('id', $variety->id)->update([
        'maturity_days_min' => 150,
        'maturity_days_max' => 135,
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseHas('crop_varieties', [
        'id' => $variety->id,
        'maturity_days_min' => null,
        'maturity_days_max' => null,
    ]);
    $this->assertDatabaseCount('crop_variety_translations', 2);
});

test('variety resource omits private source attachment data', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->create();
    $source = KnowledgeSource::factory()->create(['title' => 'Research source']);
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['source_id' => $source->id]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-varieties/'.$variety->id)
        ->assertOk()
        ->assertJsonPath('data.source_title', 'Research source')
        ->assertJsonMissingPath('data.attachments')
        ->assertJsonMissingPath('data.source.attachments')
        ->assertJsonPath('data.translations.en.name', 'HD 2967');
});

test('localized variety scopes select a database name with English fallback', function () {
    $crop = Crop::factory()->create();
    CropVariety::factory()->for($crop)->withNames('Alpha', 'Zulu')->create();
    $fallback = CropVariety::factory()->for($crop)->create();
    CropVarietyTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'Beta']);
    app()->setLocale('hi');

    $varieties = CropVariety::query()->withLocalizedName()->orderBy('localized_name')->get();

    expect($varieties->pluck('localized_name')->all())->toBe(['Beta', 'Zulu']);
    expect($varieties->first()->id)->toBe($fallback->id);
});

test('variety listings resolve header locales preserve all translations and fall back per record', function (array $headers, string $name, string $cropName) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Wheat', 'गेहूँ')->create();
    $translated = CropVariety::factory()->for($crop)->withNames('Example variety', 'उदाहरण किस्म')->create();
    $translated->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ જાત']);
    $fallback = CropVariety::factory()->for($crop)->create();
    CropVarietyTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'English fallback']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/crop-varieties?crop_id='.$crop->id.'&locale=en')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
    $rows = collect($response->json('data'))->keyBy('id');

    expect($rows[$translated->id]['name'])->toBe($name);
    expect($rows[$translated->id]['crop_name'])->toBe($cropName);
    expect($rows[$translated->id]['translations']['gu']['name'])->toBe('ઉદાહરણ જાત');
    expect($rows[$fallback->id]['name'])->toBe('English fallback');
})->with([
    'default English' => [[], 'Example variety', 'Wheat'],
    'Hindi locale header' => [['X-Locale' => 'hi'], 'उदाहरण किस्म', 'गेहूँ'],
    'Hindi language header' => [['Accept-Language' => 'hi'], 'उदाहरण किस्म', 'गेहूँ'],
    'configured Gujarati' => [['X-Locale' => 'gu'], 'ઉદાહરણ જાત', 'Wheat'],
    'unsupported header' => [['X-Locale' => 'xx'], 'Example variety', 'Wheat'],
]);

test('variety pagination sorts resolved names with English fallback and stable IDs', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $last = CropVariety::factory()->for($crop)->withNames('Alpha', 'Zulu')->create();
    $first = CropVariety::factory()->for($crop)->create();
    CropVarietyTranslation::factory()->for($first)->create(['locale' => 'en', 'name' => 'Beta']);
    $second = CropVariety::factory()->for($crop)->withNames('Gamma', 'Beta')->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?crop_id='.$crop->id.'&per_page=1')
        ->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.name', 'Beta')
        ->assertJsonPath('pagination.total', 3);
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/admin/crop-varieties?crop_id='.$crop->id.'&per_page=1&page=3')
        ->assertOk()->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.0.name', 'Zulu');
});

test('variety name searches follow the same requested translation and fallback as rendering', function (string $keyword, array $expectedKeys) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames('Unrelated crop', 'अन्य फसल')->create();
    $translated = CropVariety::factory()->for($crop)->withNames('HiddenEnglish', 'VisibleHindi')->create();
    $fallback = CropVariety::factory()->for($crop)->create();
    CropVarietyTranslation::factory()->for($fallback)->create(['locale' => 'en', 'name' => 'FallbackEnglish']);
    $records = ['translated' => $translated, 'fallback' => $fallback];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonPath('pagination.total', count($expectedKeys));

    expect(array_column($response->json('data'), 'id'))->toBe(array_map(fn (string $key): int => $records[$key]->id, $expectedKeys));
})->with([
    'requested translation' => ['VisibleHindi', ['translated']],
    'English fallback when missing' => ['FallbackEnglish', ['fallback']],
    'English does not replace an available requested translation' => ['HiddenEnglish', []],
]);

test('variety detail ignores unused locale query input and preserves edit translations', function () {
    $admin = AdminUser::factory()->create();
    $variety = CropVariety::factory()->withNames('English name', 'हिन्दी नाम')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties/'.$variety->id.'?locale=xx')
        ->assertOk()->assertJsonPath('data.name', 'हिन्दी नाम')->assertJsonPath('data.translations.en.name', 'English name');
});

test('variety writes reject unused request locale fields without changing translations', function (string $method, string $suffix, array $payload) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create();
    $data = $method === 'POST' ? cropVarietyPayload($crop, $payload) : $payload;

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crop-varieties'.str_replace('{id}', (string) $variety->id, $suffix), $data)
        ->assertUnprocessable()->assertJsonValidationErrors('locale');

    $this->assertDatabaseCount('crop_varieties', 1);
    $this->assertDatabaseCount('crop_variety_translations', 2);
    $this->assertDatabaseHas('crop_variety_translations', ['crop_variety_id' => $variety->id, 'locale' => 'en', 'name' => 'HD 2967']);
})->with([
    'create' => ['POST', '', ['locale' => 'hi']],
    'update' => ['PATCH', '/{id}', ['locale' => 'hi', 'translations' => ['en' => ['name' => 'Unexpected change']]]],
    'status' => ['PATCH', '/{id}/status', ['locale' => 'hi', 'is_active' => false]],
]);

test('variety write responses use locale headers while preserving omitted translations', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->postJson('/api/admin/crop-varieties', cropVarietyPayload($crop))
        ->assertCreated()->assertJsonPath('data.name', 'एचडी 2967');
    $id = $response->json('data.id');
    $this->patchJson('/api/admin/crop-varieties/'.$id, ['translations' => ['hi' => ['name' => 'नई किस्म']]])
        ->assertOk()->assertJsonPath('data.name', 'नई किस्म')->assertJsonPath('data.translations.en.name', 'HD 2967');
    $this->patchJson('/api/admin/crop-varieties/'.$id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.name', 'नई किस्म')->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('crop_variety_translations', ['crop_variety_id' => $id, 'locale' => 'hi', 'name' => 'नई किस्म']);
    $this->assertDatabaseCount('crop_variety_translations', 2);
});

test('variety crop-name searches use the displayed parent translation and fallback', function (string $keyword, array $expectedKeys) {
    $admin = AdminUser::factory()->create();
    $translatedCrop = Crop::factory()->withNames('HiddenCrop', 'VisibleCrop')->create();
    $fallbackCrop = Crop::factory()->create();
    CropTranslation::factory()->for($fallbackCrop)->create(['locale' => 'en', 'name' => 'FallbackCrop']);
    $records = [
        'translated' => CropVariety::factory()->for($translatedCrop)->withNames('First variety', 'पहली किस्म')->create(),
        'fallback' => CropVariety::factory()->for($fallbackCrop)->withNames('Second variety', 'दूसरी किस्म')->create(),
    ];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-varieties?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonPath('pagination.total', count($expectedKeys));

    expect(array_column($response->json('data'), 'id'))->toBe(array_map(fn (string $key): int => $records[$key]->id, $expectedKeys));
})->with([
    'requested parent name' => ['VisibleCrop', ['translated']],
    'fallback parent name' => ['FallbackCrop', ['fallback']],
    'English does not override a present parent translation' => ['HiddenCrop', []],
]);

test('localized variety listings eagerly load each required relationship once', function () {
    $admin = AdminUser::factory()->create();
    CropVariety::factory()->count(3)->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-varieties')
            ->assertOk()->assertJsonCount(3, 'data');

        foreach (['crop_variety_translations', 'crop_translations', 'crops'] as $table) {
            $queries = collect(DB::getQueryLog())->filter(
                fn (array $query): bool => str_starts_with($query['query'], 'select * from "'.$table.'"'),
            );
            expect($queries)->toHaveCount(1);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedImage;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function seedBasicPayload(array $overrides = []): array
{
    return array_replace(['crop_id' => Crop::factory()->published()->create()->id, 'name' => 'Mustard seed',
        'slug' => 'mustard-seed', 'sku' => 'TEST-001', 'material_type' => 'seed', 'treatment_status' => 'untreated',
        'default_unit_code' => 'g', 'reference_pack_quantity' => '1.0000', 'reference_pack_unit_code' => 'kg'], $overrides);
}

test('the complete seed wizard persists each step on one ID and publishes only at finalization', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $response = $this->postJson('/api/admin/seeds/step-1', seedBasicPayload())->assertCreated()
        ->assertJsonPath('data.setup_status', 'draft')->assertJsonPath('data.completed_step', 1)->assertJsonPath('data.next_step', 2)
        ->assertJsonPath('data.translations.en.name', 'Mustard seed')->assertJsonPath('data.is_active', false);
    $id = $response->json('data.id');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('seeds', 1);
    $this->patchJson('/api/admin/seeds/'.$id.'/step-2', ['translations' => ['hi' => ['name' => 'सरसों बीज']]])->assertOk()
        ->assertJsonPath('data.id', $id)->assertJsonPath('data.completed_step', 2)->assertJsonPath('data.next_step', 3);
    $this->postJson('/api/admin/seeds/'.$id.'/step-3', ['verification_status' => 'verified', 'source_id' => $source->id, 'is_active' => true])
        ->assertOk()->assertJsonPath('data.completed_step', 3)->assertJsonPath('data.requested_is_active', true)->assertJsonPath('data.is_active', false);
    expect(Seed::query()->published()->count())->toBe(0);
    $this->getJson('/api/admin/seeds/'.$id.'/review')->assertOk()->assertJsonPath('data.ready_to_finalize', true)->assertJsonPath('data.readiness_errors', []);
    $this->postJson('/api/admin/seeds/'.$id.'/finalize')->assertOk()->assertJsonPath('data.setup_status', 'completed')
        ->assertJsonPath('data.completed_step', 4)->assertJsonPath('data.next_step', null)->assertJsonPath('data.is_active', true);
    $this->postJson('/api/admin/seeds/'.$id.'/finalize')->assertOk()->assertJsonPath('data.seed_id', $id);
    $this->assertDatabaseCount('seeds', 1);
    $this->assertDatabaseCount('seed_translations', 2);
    expect(Seed::query()->published()->sole()->id)->toBe($id);
});

test('seed endpoints reject unauthenticated requests', function (string $method, string $suffix) {
    $seed = Seed::factory()->create();
    $this->json($method, '/api/admin/seeds'.str_replace('{id}', (string) $seed->id, $suffix), [])->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'show' => ['GET', '/{id}'], 'create' => ['POST', '/step-1'], 'basic' => ['PUT', '/{id}/step-1'],
    'translations' => ['PUT', '/{id}/step-2'], 'media' => ['POST', '/{id}/step-3'], 'review' => ['GET', '/{id}/review'],
    'finalize' => ['POST', '/{id}/finalize'], 'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('seed endpoints reject farmer identities inactive admins and wrong token abilities', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'wrong ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/seeds');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'wrong ability']);

test('seed management uses the explicit existing admin gate pattern', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-seeds', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seeds')->assertForbidden();
});

test('step one requires all basic fields', function () {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/step-1', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['crop_id', 'name', 'slug', 'material_type', 'treatment_status', 'default_unit_code']);
    $this->assertDatabaseCount('seeds', 0);
});

test('step one rejects invalid master and numeric fields', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $payload = seedBasicPayload([$field => $value]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/step-1', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('seeds', 0);
})->with([
    'missing crop' => ['crop_id', 999999], 'missing variety' => ['crop_variety_id', 999999], 'bad name' => ['name', str_repeat('a', 201)],
    'slug format' => ['slug', 'Bad Slug'], 'slug length' => ['slug', str_repeat('a', 221)], 'sku length' => ['sku', str_repeat('a', 101)],
    'brand length' => ['brand_name', str_repeat('a', 151)], 'producer length' => ['producer_name', str_repeat('a', 201)],
    'material type' => ['material_type', 'invalid'], 'seed type' => ['seed_type', 'invalid'], 'seed class' => ['seed_class', 'invalid'],
    'propagation' => ['propagation_method', 'invalid'], 'treatment' => ['treatment_status', 'invalid'], 'unit' => ['default_unit_code', 'litre'],
    'negative germination' => ['germination_reference_pct', -1], 'high germination' => ['germination_reference_pct', 101],
    'purity high' => ['purity_reference_pct', 100.01], 'seeds zero' => ['seeds_per_gram', 0], 'seeds negative' => ['seeds_per_gram', -1],
    'pack zero' => ['reference_pack_quantity', 0], 'pack precision' => ['reference_pack_quantity', '1.12345'],
    'seeds precision' => ['seeds_per_gram', '1.1234'], 'percentage precision' => ['purity_reference_pct', '50.123'],
    'boolean' => ['is_organic', 'yes'], 'injected publication' => ['is_active', true], 'injected verification' => ['verification_status', 'verified'],
    'injected progress' => ['completed_step', 4], 'locale request' => ['locale', 'hi'], 'client path' => ['image_path', '/tmp/evil.jpg'],
]);

test('step one rejects inactive crops and varieties from another crop', function (bool $inactive) {
    $admin = AdminUser::factory()->create();
    $payload = seedBasicPayload();
    if ($inactive) {
        Crop::findOrFail($payload['crop_id'])->forceFill(['is_active' => false])->save();
    } else {
        $payload['crop_variety_id'] = CropVariety::factory()->withNames()->create(['is_active' => true])->id;
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/step-1', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($inactive ? 'crop_id' : 'crop_variety_id');
})->with(['inactive crop' => true, 'wrong parent variety' => false]);

test('step one preserves IDs translations and gallery and keeps canonical names in the default translation only', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->completed()->create();
    $image = SeedImage::factory()->for($seed)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', ['name' => 'Renamed seed'])
        ->assertOk()->assertJsonPath('data.id', $seed->id)->assertJsonPath('data.translations.en.name', 'Renamed seed')
        ->assertJsonPath('data.translations.hi.name', 'परीक्षण बीज')->assertJsonCount(1, 'data.images');
    $this->assertDatabaseCount('seeds', 1);
    $this->assertModelExists($image);
    expect(Schema::hasColumns('seeds', ['name', 'description', 'planting_instructions']))->toBeFalse();
});

test('slug and SKU remain reserved including soft deleted seeds but current values may be retained', function (string $field) {
    $admin = AdminUser::factory()->create();
    $other = Seed::factory()->create([$field => 'reserved']);
    $other->delete();
    $payload = seedBasicPayload([$field => 'reserved']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/step-1', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $seed = Seed::factory()->create([$field => 'current']);
    $this->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', [$field => 'current'])->assertOk();
})->with(['slug', 'sku']);

test('changing crop requires a compatible variety and never silently reparents it', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->create();
    $variety = CropVariety::factory()->withNames()->create(['crop_id' => $seed->crop_id, 'is_active' => true]);
    $seed->forceFill(['crop_variety_id' => $variety->id])->save();
    $crop = Crop::factory()->published()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', ['crop_id' => $crop->id])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    $this->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', ['crop_id' => $crop->id, 'crop_variety_id' => null])->assertOk();
});

test('unit compatibility and reference pack pairing use the effective partial update state', function (array $changes, string $error) {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->create(['reference_pack_quantity' => 1, 'reference_pack_unit_code' => 'kg']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with(['incompatible material' => [['material_type' => 'grafted_plant'], 'default_unit_code'],
    'cleared quantity' => [['reference_pack_quantity' => null], 'reference_pack_quantity'], 'cleared unit' => [['reference_pack_unit_code' => null], 'reference_pack_quantity']]);

test('switching to non seed material clears only seed specific fields', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->create(['seed_type' => 'hybrid', 'seed_class' => 'certified', 'seeds_per_gram' => 40, 'is_f1_hybrid' => true, 'is_organic' => true]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/step-1', ['material_type' => 'grafted_plant', 'default_unit_code' => 'plant'])
        ->assertOk()->assertJsonPath('data.seed_type', null)->assertJsonPath('data.seeds_per_gram', null)->assertJsonPath('data.is_f1_hybrid', null)
        ->assertJsonPath('data.is_organic', true);
});

test('translations require every configured language and preserve omitted locales and fields on updates', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/seeds/'.$seed->id.'/step-2', ['translations' => ['en' => ['description' => 'Description']]])
        ->assertUnprocessable()->assertJsonValidationErrors('translations.gu.name');
    $this->putJson('/api/admin/seeds/'.$seed->id.'/step-2', ['translations' => ['gu' => ['name' => 'ગુજરાતી બીજ']]])->assertOk()->assertJsonPath('data.completed_step', 2);
    $this->putJson('/api/admin/seeds/'.$seed->id.'/step-2', ['translations' => ['en' => ['description' => 'Description']]])->assertOk()
        ->assertJsonPath('data.translations.hi.name', 'परीक्षण बीज')->assertJsonPath('data.translations.gu.name', 'ગુજરાતી બીજ');
    $this->putJson('/api/admin/seeds/'.$seed->id.'/step-2', ['translations' => ['en' => ['name' => 'New primary name']]])->assertOk()
        ->assertJsonPath('data.name', 'New primary name')->assertJsonPath('data.translations.en.description', 'Description');
    $this->assertDatabaseCount('seed_translations', 3);
});

test('translation payloads reject unsupported locales and nested foreign keys', function (array $translations, string $error) {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->putJson('/api/admin/seeds/'.$seed->id.'/step-2', ['translations' => $translations])
        ->assertUnprocessable()->assertJsonValidationErrors($error);
    expect($seed->fresh()->completed_step)->toBe(1);
})->with(['unsupported' => [['fr' => ['name' => 'French']], 'translations'], 'reparent' => [['en' => ['name' => 'X', 'seed_id' => 999]], 'translations.en'],
    'blank name' => [['hi' => ['name' => ' ']], 'translations.hi.name']]);

test('wizard steps cannot be bypassed or completed using client supplied readiness', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = Seed::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending'])->assertConflict();
    $this->getJson('/api/admin/seeds/'.$seed->id.'/review')->assertOk()->assertJsonPath('data.ready_to_finalize', false);
    $this->postJson('/api/admin/seeds/'.$seed->id.'/finalize', ['ready_to_save' => true])->assertUnprocessable()->assertJsonValidationErrors('ready_to_save');
    $this->postJson('/api/admin/seeds/'.$seed->id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors('completed_step');
    $this->patchJson('/api/admin/seeds/'.$seed->id.'/status', ['is_active' => true])->assertConflict();
    expect($seed->fresh()->is_active)->toBeFalse();
});

test('ordinary managers can finalize inactive drafts but cannot approve or publish seeds', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $source = KnowledgeSource::factory()->active()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'verified', 'source_id' => $source->id])->assertForbidden();
    $this->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending', 'is_active' => true])->assertForbidden();
    $this->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'pending'])->assertOk();
    $this->postJson('/api/admin/seeds/'.$seed->id.'/finalize')->assertOk()->assertJsonPath('data.is_active', false);
    $this->patchJson('/api/admin/seeds/'.$seed->id.'/status', ['is_active' => true])->assertForbidden();
});

test('verification requires a usable active verified source', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 2]);
    $source = KnowledgeSource::factory()->active()->create();
    $source->forceFill(match ($kind) {
        'inactive' => ['is_active' => false], 'unverified' => ['verification_status' => 'pending'],
        default => ['source_url' => null, 'reference_identifier' => null]
    })->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/step-3', ['verification_status' => 'verified', 'source_id' => $source->id])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
    expect($seed->fresh()->completed_step)->toBe(2);
})->with(['inactive', 'unverified', 'no reference']);

test('completed seed edits invalidate verification without resetting wizard progress or losing related data', function (string $step, array $payload) {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->completed()->create(['is_active' => true, 'requested_is_active' => true, 'verification_status' => 'verified']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/'.$step, $payload)->assertOk()
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.completed_step', 4)
        ->assertJsonPath('data.setup_status', 'completed')->assertJsonCount(2, 'data.translations');
})->with(['basic' => ['step-1', ['brand_name' => 'Changed']], 'translation' => ['step-2', ['translations' => ['hi' => ['description' => 'Changed']]]]]);

test('finalization rechecks related crop and saved values after earlier steps', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 3]);
    $seed->crop->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/finalize')
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');
    $seed->crop->forceFill(['is_active' => true])->save();
    DB::table('seeds')->where('id', $seed->id)->update(['germination_reference_pct' => 120]);
    $this->postJson('/api/admin/seeds/'.$seed->id.'/finalize')->assertUnprocessable()->assertJsonValidationErrors('germination_reference_pct');
});

test('listing localizes names supports literal searches and paginates before rendering', function () {
    $admin = AdminUser::factory()->create();
    $first = Seed::factory()->withTranslations('Alpha 100%', 'अल्फा')->create(['sku' => 'AA']);
    $second = Seed::factory()->withTranslations('Beta', 'बीटा')->create(['brand_name' => 'Test producer']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/seeds?per_page=1')
        ->assertOk()->assertJsonPath('data.0.name', 'अल्फा')->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.per_page', 1)->assertJsonMissingPath('data.0.images')
        ->assertJsonMissingPath('data.0.translations');
    $this->getJson('/api/admin/seeds?keyword='.urlencode('बीटा'))->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('pagination.total', 1);
    $this->getJson('/api/admin/seeds?keyword=100%25')->assertOk()->assertJsonPath('data.0.id', $first->id)->assertJsonPath('pagination.total', 1);
    $this->getJson('/api/admin/seeds?keyword=Test%20producer')->assertOk()->assertJsonPath('data.0.id', $second->id);
    $this->getJson('/api/admin/seeds?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('listing applies crop variety material publication verification and draft filters', function () {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create();
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true]);
    $seed = Seed::factory()->completed()->create(['crop_id' => $crop->id, 'crop_variety_id' => $variety->id, 'verification_status' => 'verified', 'is_active' => true]);
    Seed::factory()->withTranslations()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seeds?'.http_build_query([
        'crop_id' => $crop->id, 'crop_variety_id' => $variety->id, 'material_type' => 'seed', 'is_active' => 1, 'verification_status' => 'verified', 'setup_status' => 'completed']))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $seed->id);
    $this->getJson('/api/admin/seeds?crop_variety_id='.$variety->id)->assertUnprocessable()->assertJsonValidationErrors('crop_id');
});

test('seed names use the header locale and English fallback without request locale parameters', function (string $locale, string $expected) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/seeds/'.$seed->id)->assertOk()->assertJsonPath('data.name', $expected);
    $this->getJson('/api/admin/seeds')->assertOk()->assertJsonPath('data.0.name', $expected);
})->with(['English' => ['en', 'Test seed'], 'Hindi' => ['hi', 'परीक्षण बीज'], 'missing Gujarati' => ['gu', 'Test seed'], 'unsupported' => ['fr', 'Test seed']]);

test('reviewers activate completed verified seeds and ordinary managers may deactivate them', function () {
    $reviewer = AdminUser::factory()->superUser()->create();
    $manager = AdminUser::factory()->create();
    $source = KnowledgeSource::factory()->active()->create();
    $seed = Seed::factory()->completed()->create(['verification_status' => 'verified', 'source_id' => $source->id]);
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.requested_is_active', true);
    app('auth')->forgetGuards();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seeds/'.$seed->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.requested_is_active', false);
});

test('finalization cannot publish pending content or bypass reviewer authorization', function () {
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 3, 'requested_is_active' => true]);
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/finalize')->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/finalize')
        ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    expect($seed->fresh()->setup_status)->toBe('draft');
});

test('finalization rejects corrupt stored verification states instead of trusting wizard progress', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create(['completed_step' => 3, 'verification_status' => 'invalid']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seeds/'.$seed->id.'/finalize')
        ->assertUnprocessable()->assertJsonValidationErrors('verification_status');
});

test('listing query count stays bounded and loads only one primary thumbnail per seed', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->withTranslations()->create();
    SeedImage::factory()->for($seed)->count(3)->sequence(['sort_order' => 2], ['sort_order' => 1], ['sort_order' => 1])->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/seeds')->assertOk()->assertJsonPath('data.0.primary_image.sort_order', 1);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    Seed::factory()->withTranslations()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/seeds')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($count);
    } finally {
        DB::disableQueryLog();
    }
});

test('inactive unreferenced seeds soft delete while preserving translations images and reserved identifiers', function () {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->completed()->create();
    $image = SeedImage::factory()->for($seed)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seeds/'.$seed->id)->assertOk();
    $this->assertSoftDeleted($seed);
    $this->assertModelExists($image);
    $this->assertDatabaseCount('seed_translations', 2);
    $this->getJson('/api/admin/seeds/'.$seed->id)->assertNotFound();
    $this->getJson('/api/admin/seeds')->assertJsonPath('pagination.total', 0);
});

test('active and externally referenced seeds cannot be deleted including cascading history', function (bool $active) {
    $admin = AdminUser::factory()->create();
    $seed = Seed::factory()->create(['is_active' => $active]);
    if (! $active) {
        Schema::create('seed_history_probe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_id')->constrained('seeds')->cascadeOnDelete();
        });
        DB::table('seed_history_probe')->insert(['seed_id' => $seed->id]);
    }
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seeds/'.$seed->id)->assertConflict();
        $this->assertNotSoftDeleted($seed);
    } finally {
        if (! $active) {
            Schema::dropIfExists('seed_history_probe');
        }
    }
})->with(['active' => true, 'referenced' => false]);

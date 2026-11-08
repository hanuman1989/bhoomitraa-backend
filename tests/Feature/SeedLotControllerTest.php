<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedImage;
use App\Models\SeedLot;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function lotAvailableSeed(): Seed
{
    return Seed::factory()->completed()->create(['is_active' => true, 'verification_status' => 'verified',
        'source_id' => KnowledgeSource::factory()->active()->create()->id]);
}

test('admins create active pending lots with optional quality and certification fields absent', function () {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots', ['seed_id' => $seed->id, 'lot_number' => 'LOT-100'])
        ->assertCreated()->assertJsonPath('data.lot_number', 'LOT-100')->assertJsonPath('data.seed.id', $seed->id)
        ->assertJsonPath('data.seed.name', 'Test seed')->assertJsonPath('data.is_active', true)->assertJsonPath('data.verification_status', 'pending')
        ->assertJsonPath('data.germination_percentage', null)->assertJsonPath('data.physical_purity_percentage', null)->assertJsonPath('data.genetic_purity_percentage', null)
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null)->assertJsonPath('data.report_url', null)
        ->assertJsonPath('data.computed_validity_status', 'no_expiry')->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('seed_lots', 1);
    expect($seed->lots()->sole()->id)->toBe($response->json('data.id'));
    expect(Schema::hasTable('seed_lot_translations'))->toBeFalse();
});

test('seed lot operations reject missing bearer tokens', function (string $method, string $path) {
    $lot = SeedLot::factory()->create();
    $this->json($method, '/api/admin/seed-lots'.str_replace('{id}', (string) $lot->id, $path))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'store' => ['POST', ''], 'show' => ['GET', '/{id}'], 'update' => ['PUT', '/{id}'],
    'status' => ['PATCH', '/{id}/status'], 'review' => ['PATCH', '/{id}/verification'], 'delete' => ['DELETE', '/{id}'], 'report' => ['GET', '/{id}/report']]);

test('farmer identities inactive admins and tokens without admin ability cannot manage seed lots', function (string $kind) {
    $admin = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($admin->createToken('test', [$kind === 'wrong ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/seed-lots');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'wrong ability']);

test('seed lot routes enforce the seed management gate', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-seeds', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-lots')->assertForbidden();
});

test('seed lot creation requires seed and lot number', function () {
    $admin = AdminUser::factory()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['seed_id', 'lot_number'])->assertJsonPath('errors.lot_number.0', 'The lot number field is required.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('seed_lots', 0);
});

test('invalid lot fields return 422 without saving a lot', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots',
        array_replace(['seed_id' => $seed->id, 'lot_number' => 'LOT-1'], [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('seed_lots', 0);
})->with([
    'missing seed' => ['seed_id', 999999], 'negative seed' => ['seed_id', -1], 'seed array' => ['seed_id', []],
    'lot length' => ['lot_number', str_repeat('a', 101)], 'blank lot' => ['lot_number', '  '],
    'seed class' => ['seed_class', 'invalid'], 'treatment' => ['seed_treatment_status', 'invalid'],
    'bad date' => ['production_date', '2026-02-30'], 'timestamp' => ['test_date', '2026-01-01T00:00:00Z'], 'bad validity' => ['valid_until', '2026-2-1'],
    'germination negative' => ['germination_percentage', -0.01], 'germination high' => ['germination_percentage', 100.01],
    'physical purity high' => ['physical_purity_percentage', 101], 'genetic purity negative' => ['genetic_purity_percentage', -1],
    'precision' => ['genetic_purity_percentage', '90.123'], 'not numeric' => ['physical_purity_percentage', 'unknown'],
    'certification number length' => ['certification_number', str_repeat('a', 101)], 'body length' => ['certification_body', str_repeat('a', 201)],
    'verification state' => ['verification_status', 'invalid'], 'active flag' => ['is_active', 'yes'],
    'forged reviewer' => ['verified_by', 1], 'forged review date' => ['verified_at', '2026-01-01'], 'forged crop' => ['crop_id', 1],
    'forged variety' => ['crop_variety_id', 1], 'translations' => ['translations', ['en' => ['name' => 'No']]], 'locale' => ['locale', 'hi'],
    'report path injection' => ['report_file', 'private/path.pdf'],
]);

test('lot numbers are unique within the effective seed while other products may reuse them', function () {
    $admin = AdminUser::factory()->create();
    $first = lotAvailableSeed();
    $second = lotAvailableSeed();
    $lot = SeedLot::factory()->for($first)->create(['lot_number' => 'SAME']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots', ['seed_id' => $first->id, 'lot_number' => 'SAME'])
        ->assertUnprocessable()->assertJsonValidationErrors('lot_number');
    $other = $this->postJson('/api/admin/seed-lots', ['seed_id' => $second->id, 'lot_number' => 'SAME'])->assertCreated()->json('data.id');
    $this->patchJson('/api/admin/seed-lots/'.$lot->id, ['lot_number' => 'SAME'])->assertOk()->assertJsonPath('data.id', $lot->id);
    $this->patchJson('/api/admin/seed-lots/'.$other, ['seed_id' => $first->id])->assertUnprocessable()->assertJsonValidationErrors('lot_number');
    $this->assertDatabaseCount('seed_lots', 2);
});

test('new lots require available seeds rather than drafts inactive or unverified products', function (string $state) {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $seed->forceFill(match ($state) {
        'draft' => ['setup_status' => 'draft'], 'inactive' => ['is_active' => false], 'pending' => ['verification_status' => 'pending']
    })->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots', ['seed_id' => $seed->id, 'lot_number' => 'L'])
        ->assertUnprocessable()->assertJsonValidationErrors('seed_id');
})->with(['draft', 'inactive', 'pending']);

test('historical lots remain manageable when their parent seed is inactive', function () {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $lot->seed->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id, ['notes' => 'Historical record'])
        ->assertOk()->assertJsonPath('data.notes', 'Historical record')->assertJsonPath('data.seed.is_active', false);
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.verification_status', 'pending');
    $this->getJson('/api/admin/seed-lots?seed_id='.$lot->seed_id)->assertOk()->assertJsonPath('pagination.total', 1);
    expect(SeedLot::query()->selectable()->count())->toBe(0);
});

test('date relationships use effective saved values during partial updates', function (array $changes, string $error) {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create(['production_date' => '2020-01-10', 'test_date' => '2020-01-20', 'valid_until' => '2020-02-10']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with(['test before production' => [['test_date' => '2020-01-01'], 'test_date'],
    'validity before production' => [['valid_until' => '2020-01-01'], 'valid_until'],
    'production after test' => [['production_date' => '2020-01-25'], 'test_date']]);

test('historical dates leap days and optional quality for planting materials are retained without fabricated results', function () {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $seed->forceFill(['material_type' => 'grafted_plant', 'default_unit_code' => 'plant'])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-lots', [
        'seed_id' => $seed->id, 'lot_number' => 'OLD', 'production_date' => '2020-02-29', 'test_date' => '2021-01-01', 'valid_until' => '2020-12-31'])
        ->assertCreated()->assertJsonPath('data.production_date', '2020-02-29')->assertJsonPath('data.test_date', '2021-01-01')
        ->assertJsonPath('data.germination_percentage', null)->assertJsonPath('data.computed_validity_status', 'expired');
});

test('review decisions record the authenticated reviewer and server timestamp without changing lot activity', function () {
    $this->travelTo('2026-11-07 12:00:00.123456');
    $admin = AdminUser::factory()->superUser()->create();
    $lot = SeedLot::factory()->create(['is_active' => false]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id.'/verification', ['verification_status' => 'verified'])
        ->assertOk()->assertJsonPath('data.verified_by', $admin->id)->assertJsonPath('data.verified_at', '2026-11-07T12:00:00.123456Z')
        ->assertJsonPath('data.verifier.id', $admin->id)->assertJsonPath('data.is_active', false);
    $this->travel(1)->hour();
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/verification', ['verification_status' => 'verified'])->assertOk()
        ->assertJsonPath('data.verified_at', '2026-11-07T12:00:00.123456Z');
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/verification', ['verification_status' => 'rejected'])->assertOk()
        ->assertJsonPath('data.verified_by', $admin->id)->assertJsonPath('data.verified_at', '2026-11-07T13:00:00.123456Z');
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/verification', ['verification_status' => 'pending'])->assertOk()
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null)->assertJsonPath('data.is_active', false);
    $this->travel(1)->hour();
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/verification', ['verification_status' => 'verified'])->assertOk()
        ->assertJsonPath('data.verified_at', '2026-11-07T14:00:00.123456Z');
});

test('ordinary managers cannot verify or reject lots through any write endpoint', function (string $method, string $path) {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->json($method, '/api/admin/seed-lots'.str_replace('{id}', (string) $lot->id, $path),
        ['seed_id' => $lot->seed_id, 'lot_number' => 'L', 'verification_status' => 'verified'])->assertForbidden();
    expect($lot->fresh()->verified_by)->toBeNull();
})->with(['create' => ['POST', ''], 'update' => ['PATCH', '/{id}'], 'verify' => ['PATCH', '/{id}/verification']]);

test('verified creates and explicit reapproval of edited content set fresh server metadata', function () {
    $this->travelTo('2026-11-07 12:00:00');
    $admin = AdminUser::factory()->superUser()->create();
    $seed = lotAvailableSeed();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $id = $this->postJson('/api/admin/seed-lots', ['seed_id' => $seed->id, 'lot_number' => 'VERIFIED', 'verification_status' => 'verified'])
        ->assertCreated()->assertJsonPath('data.verified_by', $admin->id)->json('data.id');
    $this->travel(1)->hour();
    $this->patchJson('/api/admin/seed-lots/'.$id, ['physical_purity_percentage' => '99.25', 'verification_status' => 'verified'])->assertOk()
        ->assertJsonPath('data.physical_purity_percentage', '99.25')->assertJsonPath('data.verified_at', '2026-11-07T13:00:00.000000Z');
    $this->patchJson('/api/admin/seed-lots/'.$id, ['notes' => 'Changed details'])->assertOk()->assertJsonPath('data.verification_status', 'pending')
        ->assertJsonPath('data.verified_by', null)->assertJsonPath('data.verified_at', null)->assertJsonPath('data.is_active', true);
});

test('activity toggles do not change verification and pending lots may be active', function () {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.verification_status', 'pending');
    $this->patchJson('/api/admin/seed-lots/'.$lot->id.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.verification_status', 'pending')->assertJsonPath('data.verified_at', null);
    expect(SeedLot::query()->selectable()->sole()->id)->toBe($lot->id);
});

test('verification and status endpoints reject forged metadata and cross operation fields', function (string $suffix, array $payload, string $error) {
    $admin = AdminUser::factory()->superUser()->create();
    $lot = SeedLot::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-lots/'.$lot->id.'/'.$suffix, $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($error);
})->with(['reviewer' => ['verification', ['verification_status' => 'verified', 'verified_by' => 1], 'verified_by'],
    'timestamp' => ['verification', ['verification_status' => 'verified', 'verified_at' => '2020-01-01'], 'verified_at'],
    'status field injection' => ['status', ['is_active' => true, 'verification_status' => 'verified'], 'verification_status'],
    'bad review status' => ['verification', ['verification_status' => 'invalid'], 'verification_status']]);

test('validity filters include exact boundaries and never treat NULL as expired', function (string $status, array $expected) {
    $this->travelTo('2026-11-07 12:00:00');
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    foreach (['past' => '2026-11-06', 'today' => '2026-11-07', 'boundary' => '2026-12-07', 'future' => '2026-12-08', 'none' => null] as $name => $date) {
        SeedLot::factory()->for($seed)->create(['lot_number' => $name, 'valid_until' => $date]);
    }
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-lots?validity_status='.$status)
        ->assertOk()->assertJsonPath('pagination.total', count($expected));
    expect(array_column($response->json('data'), 'lot_number'))->toBe($expected);
    if ($status !== 'all') {
        expect(array_unique(array_column($response->json('data'), 'computed_validity_status')))->toBe([$status]);
    }
})->with(['all' => ['all', ['boundary', 'future', 'none', 'past', 'today']], 'valid' => ['valid', ['future']],
    'soon' => ['expiring_soon', ['boundary', 'today']], 'expired' => ['expired', ['past']], 'none' => ['no_expiry', ['none']]]);

test('validity window follows configuration and application calendar timezone', function (string $timezone, string $now, string $todayDate, string $nextDate) {
    config(['app.timezone' => $timezone, 'seed_lots.expiring_soon_days' => 0]);
    $this->travelTo($now);
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $today = SeedLot::factory()->for($seed)->create(['valid_until' => $todayDate]);
    $next = SeedLot::factory()->for($seed)->create(['valid_until' => $nextDate]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-lots?validity_status=expiring_soon')
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $today->id)->assertJsonPath('data.0.computed_validity_status', 'expiring_soon');
    $this->getJson('/api/admin/seed-lots?validity_status=valid')->assertOk()->assertJsonPath('data.0.id', $next->id);
})->with(['ahead of UTC' => ['Asia/Kolkata', '2026-11-06 20:00:00 UTC', '2026-11-07', '2026-11-08'],
    'behind UTC' => ['America/Los_Angeles', '2026-11-07 03:00:00 UTC', '2026-11-06', '2026-11-07']]);

test('keyword searches cover lot certification localized seed name SKU and literal wildcards', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $seed->translations()->where('locale', 'hi')->update(['name' => 'सरसों उत्पाद']);
    $seed->forceFill(['sku' => 'SKU-77'])->save();
    $lot = SeedLot::factory()->for($seed)->create(['lot_number' => 'BATCH-100%', 'certification_number' => 'CERT-99']);
    SeedLot::factory()->create(['lot_number' => 'Other lot']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')->getJson('/api/admin/seed-lots?keyword='.urlencode($keyword))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $lot->id);
})->with(['BATCH', 'CERT-99', 'सरसों', 'SKU-77', '100%']);

test('listing filters and pagination preserve constraints and only load the primary seed image', function () {
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $image = SeedImage::factory()->for($seed)->create(['sort_order' => 1]);
    SeedImage::factory()->for($seed)->create(['sort_order' => 2]);
    SeedLot::factory()->for($seed)->count(3)->sequence(['lot_number' => 'A'], ['lot_number' => 'B'], ['lot_number' => 'C'])->create(['verification_status' => 'verified', 'is_active' => false]);
    SeedLot::factory()->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-lots?'.http_build_query([
        'seed_id' => $seed->id, 'verification_status' => 'verified', 'is_active' => 0, 'per_page' => 2]))
        ->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonPath('data.0.lot_number', 'A')
        ->assertJsonPath('data.0.seed.primary_image.id', $image->id)->assertJsonMissingPath('data.0.seed.images');
    expect($response->json('pagination.next_page_url'))->toContain('seed_id='.$seed->id, 'verification_status=verified', 'is_active=0', 'per_page=2');
    $this->getJson('/api/admin/seed-lots?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/admin/seed-lots?validity_status=invalid')->assertUnprocessable()->assertJsonValidationErrors('validity_status');
});

test('linked seed crop and variety labels use header locales with English fallback', function (string $locale, string $expected) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $seed = lotAvailableSeed();
    $crop = Crop::factory()->published('Wheat', 'गेहूँ')->create();
    $variety = CropVariety::factory()->for($crop)->withNames('Variety', 'किस्म')->create(['is_active' => true]);
    $seed->forceFill(['crop_id' => $crop->id, 'crop_variety_id' => $variety->id])->save();
    $lot = SeedLot::factory()->for($seed)->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/seed-lots/'.$lot->id)->assertOk()->assertJsonPath('data.seed.name', $expected);
    $response->assertJsonPath('data.seed.crop_name', $locale === 'hi' ? 'गेहूँ' : 'Wheat')->assertJsonPath('data.seed.crop_variety_name', $locale === 'hi' ? 'किस्म' : 'Variety');
})->with(['English' => ['en', 'Test seed'], 'Hindi' => ['hi', 'परीक्षण बीज'], 'missing Gujarati' => ['gu', 'Test seed']]);

test('listing query counts remain bounded as lots from distinct products grow', function () {
    $admin = AdminUser::factory()->create();
    SeedLot::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/seed-lots')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    SeedLot::factory()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/seed-lots')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('active or historically referenced lots cannot be deleted and references cannot be reparented', function (bool $active) {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create(['is_active' => $active]);
    if (! $active) {
        Schema::create('lot_history_probe', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seed_lot_id')->constrained('seed_lots')->cascadeOnDelete();
        });
        DB::table('lot_history_probe')->insert(['seed_lot_id' => $lot->id]);
    }
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seed-lots/'.$lot->id)->assertConflict();
        $this->assertModelExists($lot);
        if (! $active) {
            $this->patchJson('/api/admin/seed-lots/'.$lot->id, ['seed_id' => lotAvailableSeed()->id])->assertUnprocessable()->assertJsonValidationErrors('seed_id');
            $this->assertDatabaseCount('lot_history_probe', 1);
        }
    } finally {
        if (! $active) {
            Schema::dropIfExists('lot_history_probe');
        }
    }
})->with(['active' => true, 'historical reference' => false]);

test('unreferenced inactive lots delete without soft delete assumptions', function () {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create(['is_active' => false]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seed-lots/'.$lot->id)->assertOk();
    $this->assertModelMissing($lot);
    expect(Schema::hasColumn('seed_lots', 'deleted_at'))->toBeFalse();
});

test('seed deletion protection discovers actual lot references', function () {
    $admin = AdminUser::factory()->create();
    $lot = SeedLot::factory()->create();
    $lot->seed->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seeds/'.$lot->seed_id)->assertConflict();
    $this->assertDatabaseCount('seed_lots', 1);
});

test('database constraints reject quality values outside their allowed range', function (string $field, float $value) {
    $lot = SeedLot::factory()->create();
    expect(fn () => DB::table('seed_lots')->where('id', $lot->id)->update([$field => $value]))->toThrow(QueryException::class);
})->with(['germination_percentage', 'physical_purity_percentage', 'genetic_purity_percentage'])->with([-0.01, 100.01]);

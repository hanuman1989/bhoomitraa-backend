<?php

use App\Models\AdminUser;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Seed;
use App\Models\SeedImage;
use App\Models\SeedPrice;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

function priceSeed(): Seed
{
    return Seed::factory()->withTranslations('Mustard seed', 'सरसों बीज')->create(['setup_status' => 'completed', 'completed_step' => 4,
        'is_active' => true, 'verification_status' => 'verified', 'sku' => 'MS-001', 'source_id' => KnowledgeSource::factory()->active()->create()->id]);
}

/** @return array<string, mixed> */
function pricePayload(Seed $seed, array $overrides = []): array
{
    return array_replace(['seed_id' => $seed->id, 'price_type' => 'retail', 'package_quantity' => '1.0000', 'package_unit_code' => 'kg',
        'package_price' => '200.0000', 'region_scope' => 'all', 'effective_from' => '2026-11-07'], $overrides);
}

function priceDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('managers create inactive all-region prices with decimal strings and configured default currency', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed))
        ->assertCreated()->assertJsonPath('data.currency_code', 'INR')->assertJsonPath('data.package_quantity', '1.0000')
        ->assertJsonPath('data.package_price', '200.0000')->assertJsonPath('data.region_scope', 'all')->assertJsonPath('data.region_ids', [])
        ->assertJsonPath('data.regions', [])->assertJsonPath('data.is_active', false)->assertJsonPath('data.last_verified_at', null)
        ->assertJsonPath('data.seed.name', 'Mustard seed')->assertJsonPath('data.effective_from', '2026-11-07')->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('seed_prices', 1);
    $this->assertDatabaseCount('seed_price_regions', 0);
    expect($seed->prices()->sole()->id)->toBe($response->json('data.id'));
    expect(Schema::hasTable('seed_price_translations'))->toBeFalse();
});

test('specific prices save district IDs across states with existing region response fields', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $first = priceDistrict();
    $second = priceDistrict('Indore');
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, [
        'region_scope' => 'specific', 'region_ids' => [$second->id, $first->id]]))->assertCreated()
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])->assertJsonPath('data.regions.0.parent_id', $first->parent_id)
        ->assertJsonPath('data.regions.0.name', 'Jaipur')->assertJsonPath('data.regions.0.is_active', true);
    $price = SeedPrice::findOrFail($response->json('data.id'));
    expect($first->seedPrices()->sole()->id)->toBe($price->id);
    $this->assertDatabaseCount('seed_price_regions', 2);
});

test('seed price endpoints reject missing admin authentication', function (string $method, string $path) {
    $price = SeedPrice::factory()->create();
    $this->json($method, '/api/admin/seed-prices'.str_replace('{id}', (string) $price->id, $path))->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['list' => ['GET', ''], 'create' => ['POST', ''], 'show' => ['GET', '/{id}'], 'update' => ['PATCH', '/{id}'], 'status' => ['PATCH', '/{id}/status'], 'delete' => ['DELETE', '/{id}']]);

test('farmer identities inactive admins and wrong abilities cannot manage seed prices', function (string $kind) {
    $user = $kind === 'farmer' ? User::factory()->create() : AdminUser::factory()->create(['status' => $kind !== 'inactive']);
    $response = $this->withToken($user->createToken('test', [$kind === 'wrong ability' ? 'frontend' : 'admin'])->plainTextToken)->getJson('/api/admin/seed-prices');
    $kind === 'farmer' ? $response->assertUnauthorized() : $response->assertForbidden();
})->with(['farmer', 'inactive', 'wrong ability']);

test('seed price endpoints enforce the seed management gate', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-seeds', fn (Authenticatable $user): bool => false);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-prices')->assertForbidden();
});

test('price creation requires the authoritative package and effective-date fields', function () {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['seed_id', 'price_type', 'package_quantity', 'package_unit_code', 'package_price', 'region_scope', 'effective_from']);
});

test('invalid price fields and screenshot-only fields return 422 without persistence', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('seed_prices', 0);
})->with([
    'missing seed' => ['seed_id', 999999], 'seed array' => ['seed_id', []], 'type' => ['price_type', 'invalid'],
    'quantity zero' => ['package_quantity', 0], 'quantity negative' => ['package_quantity', -1], 'quantity precision' => ['package_quantity', '1.12345'],
    'quantity overflow' => ['package_quantity', '10000000000'], 'price negative' => ['package_price', -0.01],
    'price precision' => ['package_price', '12.12345'], 'price overflow' => ['package_price', '1000000000000'],
    'formatted price' => ['package_price', '1,250.00'], 'price symbol' => ['package_price', '₹200'],
    'unit' => ['package_unit_code', 'litre'], 'currency' => ['currency_code', 'USD'], 'currency case' => ['currency_code', 'inr'],
    'seller length' => ['seller_name', str_repeat('a', 201)], 'invalid date' => ['effective_from', '2026-02-30'],
    'date timestamp' => ['effective_from', '2026-11-07T00:00:00Z'], 'end before start' => ['effective_to', '2026-11-06'],
    'unknown scope' => ['region_scope', 'districts'], 'noncanonical scope' => ['region_scope', 'specific_regions'],
    'unknown source' => ['source_id', 999999],
    'client verification time' => ['last_verified_at', '2026-11-07'], 'notes not approved' => ['notes', 'UI-only note'],
    'minimum order not approved' => ['minimum_order_quantity', 1], 'maximum order not approved' => ['maximum_order_quantity', 10],
    'per unit not package' => ['price_per_unit', 200], 'separate availability' => ['is_available', true], 'locale request' => ['locale', 'hi'],
]);

test('zero package prices are allowed and no currency symbols are stored', function () {
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload(priceSeed(), ['package_price' => '0']))
        ->assertCreated()->assertJsonPath('data.package_price', '0.0000')->assertJsonPath('data.currency_code', 'INR');
});

test('material-incompatible units and unavailable seed selections are rejected', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $seed->forceFill(match ($kind) {
        'inactive' => ['is_active' => false], 'draft' => ['setup_status' => 'draft'],
        'unverified' => ['verification_status' => 'pending'], 'plant with mass unit' => ['material_type' => 'grafted_plant', 'default_unit_code' => 'plant']
    })->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed))->assertUnprocessable()
        ->assertJsonValidationErrors($kind === 'plant with mass unit' ? 'package_unit_code' : 'seed_id');
})->with(['inactive', 'draft', 'unverified', 'plant with mass unit']);

test('district selections reject duplicates invalid hierarchy IDs inactive newcomers and all-region mappings', function (string $kind) {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $district = priceDistrict();
    if ($kind === 'inactive') {
        $district->forceFill(['is_active' => false])->save();
    }
    $ids = match ($kind) {
        'duplicate' => [$district->id, $district->id], 'state' => [$district->parent_id], 'unknown' => [999999], default => [$district->id]
    };
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, [
        'region_scope' => $kind === 'all with districts' ? 'all' : 'specific', 'region_ids' => $ids]))->assertUnprocessable();
    $this->assertDatabaseCount('seed_prices', 0);
    $this->assertDatabaseCount('seed_price_regions', 0);
})->with(['duplicate', 'state', 'unknown', 'inactive', 'all with districts']);

test('region scope switches and partial edits preserve inactive saved districts until explicitly removed', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $first = priceDistrict();
    $second = priceDistrict('Ajmer', $first->parent);
    $third = priceDistrict('Alwar', $first->parent);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $id = $this->postJson('/api/admin/seed-prices', pricePayload($seed, ['region_scope' => 'specific', 'region_ids' => [$first->id, $second->id]]))->assertCreated()->json('data.id');
    $first->forceFill(['is_active' => false])->save();
    $this->patchJson('/api/admin/seed-prices/'.$id, ['package_price' => '250'])->assertOk()->assertJsonPath('data.region_ids', [$first->id, $second->id])
        ->assertJsonPath('data.regions.0.is_active', false);
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_ids' => [$first->id, $third->id]])->assertOk()->assertJsonPath('data.region_ids', [$first->id, $third->id]);
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_ids' => [$third->id]])->assertOk()->assertJsonPath('data.region_ids', [$third->id]);
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_ids' => [$first->id, $third->id]])->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_scope' => 'all'])->assertOk()->assertJsonPath('data.region_ids', []);
    $this->assertDatabaseCount('seed_price_regions', 0);
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_scope' => 'specific'])->assertUnprocessable()->assertJsonValidationErrors('region_ids');
    $this->patchJson('/api/admin/seed-prices/'.$id, ['region_scope' => 'specific', 'region_ids' => [$third->id]])->assertOk()->assertJsonPath('data.region_ids', [$third->id]);
});

test('activation and active edits require reviewer authorization and verification time is server controlled', function () {
    $this->travelTo('2026-11-07 12:00:00.123456');
    $manager = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, ['is_active' => true]))->assertForbidden();
    $price = SeedPrice::factory()->for($seed)->create();
    $this->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => true])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.last_verified_at', '2026-11-07T12:00:00.123456Z');
    app('auth')->forgetGuards();
    $this->withToken($manager->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id, ['package_price' => 500])->assertForbidden();
    $this->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.last_verified_at', '2026-11-07T12:00:00.123456Z');
    $this->patchJson('/api/admin/seed-prices/'.$price->id, ['package_price' => 500])->assertOk()->assertJsonPath('data.last_verified_at', null);
});

test('active prices reject overlapping identical classifications regardless of price amount', function (array $overrides) {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $existing = SeedPrice::factory()->for($seed)->create(['is_active' => true]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, ['is_active' => true, ...$overrides]))->assertConflict();
    expect($response->json('message'))->toContain('price '.$existing->id.' ');
    $this->assertDatabaseCount('seed_prices', 1);
})->with(['duplicate' => [[]], 'different price' => [['package_price' => 300]], 'equivalent mass package' => [['package_quantity' => 1000, 'package_unit_code' => 'g']]]);

test('package type seller currency and date differences permit nonconflicting active prices', function (array $overrides) {
    config()->set('seed_prices.currencies', ['INR', 'USD']);
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    SeedPrice::factory()->for($seed)->create(['is_active' => true, 'effective_to' => '2026-11-30']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, ['is_active' => true, ...$overrides]))->assertCreated();
    $this->assertDatabaseCount('seed_prices', 2);
})->with(['package size' => [['package_quantity' => 2]], 'different grams' => [['package_unit_code' => 'g', 'package_quantity' => 1001]],
    'price type' => [['price_type' => 'wholesale']], 'seller' => [['seller_name' => 'Another seller']], 'currency' => [['currency_code' => 'USD']],
    'later period' => [['effective_from' => '2026-12-01']], 'earlier period' => [['effective_from' => '2026-10-01', 'effective_to' => '2026-11-06']]]);

test('overlap checks include touching boundaries open intervals and case insensitive seller identity', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    SeedPrice::factory()->for($seed)->create(['is_active' => true, 'seller_name' => 'Dealer', 'effective_from' => '2026-11-01', 'effective_to' => $kind === 'open interval' ? null : '2026-11-10']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, [
        'seller_name' => 'dealer', 'is_active' => true, 'effective_from' => $kind === 'open interval' ? '2030-01-01' : '2026-11-10', 'effective_to' => null]))->assertConflict();
})->with(['touching boundary', 'open interval']);

test('all-region and intersecting district prices conflict while disjoint districts coexist', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $first = priceDistrict();
    $second = priceDistrict('Ajmer', $first->parent);
    $existing = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'region_scope' => $kind === 'existing all' ? 'all' : 'specific']);
    if ($existing->region_scope === 'specific') {
        $existing->regions()->attach($first);
    }
    $payload = pricePayload($seed, ['is_active' => true, 'region_scope' => $kind === 'new all' ? 'all' : 'specific',
        'region_ids' => $kind === 'new all' ? [] : [$kind === 'disjoint' ? $second->id : $first->id]]);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', $payload);
    $kind === 'disjoint' ? $response->assertCreated() : $response->assertConflict();
})->with(['existing all', 'new all', 'shared district', 'disjoint']);

test('inactive overlaps are allowed but activation rechecks conflicts and preserves district mappings', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $district = priceDistrict();
    SeedPrice::factory()->for($seed)->create(['is_active' => true]);
    $price = SeedPrice::factory()->for($seed)->create(['region_scope' => 'specific']);
    $price->regions()->attach($district);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => true])->assertConflict();
    expect($price->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseCount('seed_price_regions', 1);
});

test('active edits exclude their own ID but reject changes overlapping another price', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $first = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'effective_to' => '2026-11-10']);
    $second = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'effective_from' => '2026-11-11']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$first->id, ['package_price' => '225.1234'])
        ->assertOk()->assertJsonPath('data.id', $first->id)->assertJsonPath('data.package_price', '225.1234');
    $this->patchJson('/api/admin/seed-prices/'.$second->id, ['effective_from' => '2026-11-10'])->assertConflict();
    expect($second->fresh()->effective_from->toDateString())->toBe('2026-11-11');
});

test('activation rejects corrupt stored prices even when primitive fields are omitted from the status payload', function (string $field, mixed $value) {
    $admin = AdminUser::factory()->superUser()->create();
    $price = SeedPrice::factory()->create([$field => $value]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['quantity' => ['package_quantity', 0], 'price' => ['package_price', -1], 'currency' => ['currency_code', 'XXX'], 'type' => ['price_type', 'invalid']]);

test('new inactive sources are rejected and active price source eligibility is rechecked', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    $source = KnowledgeSource::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, ['source_id' => $source->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');
    $source->forceFill(['is_active' => true, 'verification_status' => 'verified'])->save();
    $price = SeedPrice::factory()->for($seed)->create(['source_id' => $source->id]);
    $source->forceFill(['is_active' => false])->save();
    $this->patchJson('/api/admin/seed-prices/'.$price->id, ['seller_name' => 'Historical'])->assertOk();
    $this->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('source_id');
});

test('listing supports localized seed and SKU search seller search filters and server-side pagination', function (string $keyword) {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $price = SeedPrice::factory()->for($seed)->create(['seller_name' => 'Seller 100%']);
    SeedPrice::factory()->create(['seller_name' => 'Other']);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seed-prices?keyword='.urlencode($keyword))->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $price->id);
})->with(['सरसों', 'MS-001', 'Seller', '100%']);

test('listing applies filters without loading district maps or complete image galleries', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $district = priceDistrict();
    $image = SeedImage::factory()->for($seed)->create();
    SeedImage::factory()->for($seed)->create(['sort_order' => 2]);
    $price = SeedPrice::factory()->for($seed)->create(['region_scope' => 'specific']);
    $price->regions()->attach($district);
    SeedPrice::factory()->for($seed)->create();
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->getJson('/api/admin/seed-prices?'.http_build_query([
        'seed_id' => $seed->id, 'price_type' => 'retail', 'region_scope' => 'specific', 'is_active' => 0, 'per_page' => 1]))
        ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.seed.primary_image.id', $image->id)
        ->assertJsonMissingPath('data.0.region_ids')->assertJsonMissingPath('data.0.regions')->assertJsonMissingPath('data.0.seed.images');
    $this->getJson('/api/admin/seed-prices?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

test('seed source and district labels resolve Hindi and English fallback with bounded query counts', function (string $locale, string $name) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $district = priceDistrict();
    $source = KnowledgeSource::factory()->active()->create(['title' => 'Reference title']);
    $source->translations()->create(['locale' => 'hi', 'title' => 'स्रोत']);
    $price = SeedPrice::factory()->for($seed)->create(['source_id' => $source->id, 'region_scope' => 'specific']);
    $price->regions()->attach($district);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)->getJson('/api/admin/seed-prices/'.$price->id)
        ->assertOk()->assertJsonPath('data.seed.name', $name)->assertJsonPath('data.regions.0.name', 'Jaipur')
        ->assertJsonPath('data.source_title', $locale === 'hi' ? 'स्रोत' : 'Reference title');
    expect($response->getContent())->not->toContain('attachments', 'review_notes', 'password');
})->with(['Hindi' => ['hi', 'सरसों बीज'], 'fallback' => ['gu', 'Mustard seed']]);

test('listing query counts stay bounded as prices from distinct seeds increase', function () {
    $admin = AdminUser::factory()->create();
    SeedPrice::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->getJson('/api/admin/seed-prices')->assertOk();
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    SeedPrice::factory()->count(10)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->getJson('/api/admin/seed-prices')->assertOk()->assertJsonCount(11, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($initial);
    } finally {
        DB::disableQueryLog();
    }
});

test('mapping insert failure rolls back both the price and all district rows', function () {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $district = priceDistrict();
    DB::unprepared("CREATE TRIGGER fail_price_region_insert BEFORE INSERT ON seed_price_regions BEGIN SELECT RAISE(ABORT, 'Simulated mapping failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices', pricePayload($seed, ['region_scope' => 'specific', 'region_ids' => [$district->id]]))
            ->assertInternalServerError()->assertJsonPath('message', 'Unable to create the seed price. Please try again later.');
        $this->assertDatabaseCount('seed_prices', 0);
        $this->assertDatabaseCount('seed_price_regions', 0);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_price_region_insert');
    }
});

test('referenced historical prices cannot be deleted or rewritten while status remains independently editable', function () {
    $admin = AdminUser::factory()->create();
    $price = SeedPrice::factory()->create();
    Schema::create('price_history_probe', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('seed_price_id')->constrained('seed_prices')->cascadeOnDelete();
    });
    DB::table('price_history_probe')->insert(['seed_price_id' => $price->id]);
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seed-prices/'.$price->id)->assertConflict();
        $this->patchJson('/api/admin/seed-prices/'.$price->id, ['package_price' => '300'])->assertUnprocessable();
        $this->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => false])->assertOk();
        $this->assertDatabaseCount('price_history_probe', 1);
        expect($price->fresh()->package_price)->toBe('200.0000');
    } finally {
        Schema::dropIfExists('price_history_probe');
    }
});

test('active price deletion is blocked and inactive unreferenced prices delete their own mappings only', function () {
    $admin = AdminUser::factory()->create();
    $price = SeedPrice::factory()->create(['region_scope' => 'specific']);
    $district = priceDistrict();
    $price->regions()->attach($district);
    $active = SeedPrice::factory()->create(['is_active' => true]);
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->deleteJson('/api/admin/seed-prices/'.$active->id)->assertConflict();
    $this->deleteJson('/api/admin/seed-prices/'.$price->id)->assertOk();
    $this->assertModelMissing($price);
    $this->assertModelExists($district);
    $this->assertDatabaseCount('seed_price_regions', 0);
});

test('count package aliases conflict while count and mass quantities remain distinct', function (string $kind) {
    $admin = AdminUser::factory()->superUser()->create();
    $seed = priceSeed();
    if ($kind === 'plant and piece') {
        $seed->forceFill(['material_type' => 'grafted_plant', 'default_unit_code' => 'plant'])->save();
    }
    SeedPrice::factory()->for($seed)->create(['is_active' => true, 'package_unit_code' => 'piece']);
    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->postJson('/api/admin/seed-prices',
        pricePayload($seed, ['is_active' => true, 'package_unit_code' => $kind === 'plant and piece' ? 'plant' : 'kg']));
    $kind === 'plant and piece' ? $response->assertConflict() : $response->assertCreated();
})->with(['plant and piece', 'mass and count']);

test('active saved inactive districts stay selectable and deactivation preserves the full price period', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $price = SeedPrice::factory()->create(['region_scope' => 'specific', 'is_active' => true,
        'effective_from' => '2026-11-01', 'effective_to' => '2026-12-31']);
    $district = priceDistrict();
    $price->regions()->attach($district);
    $district->forceFill(['is_active' => false])->save();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id,
        ['region_ids' => [$district->id], 'package_price' => '250'])->assertOk()
        ->assertJsonPath('data.regions.0.is_active', false)->assertJsonPath('data.is_active', true);
    $this->patchJson('/api/admin/seed-prices/'.$price->id.'/status', ['is_active' => false])->assertOk()
        ->assertJsonPath('data.region_ids', [$district->id])->assertJsonPath('data.effective_from', '2026-11-01')
        ->assertJsonPath('data.effective_to', '2026-12-31');
    expect($price->fresh()->package_price)->toBe('250.0000');
});

test('each listing filter independently excludes a differing record', function (string $field) {
    $admin = AdminUser::factory()->create();
    $price = SeedPrice::factory()->create();
    $other = SeedPrice::factory()->for($price->seed)->create(match ($field) {
        'seed_id' => ['seed_id' => Seed::factory()->completed()->create()->id],
        'price_type' => ['price_type' => 'wholesale'],
        'region_scope' => ['region_scope' => 'specific'],
        'is_active' => ['is_active' => true],
    });
    if ($field === 'region_scope') {
        $other->regions()->attach(priceDistrict());
    }
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/seed-prices?'.http_build_query([$field => $price->{$field}]))->assertOk()
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('data.0.id', $price->id);
})->with(['seed_id', 'price_type', 'region_scope', 'is_active']);

test('pagination uses stable ID ties and preserves the allowed filters', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $prices = SeedPrice::factory()->count(3)->for($seed)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken);
    $first = $this->getJson('/api/admin/seed-prices?'.http_build_query(['seed_id' => $seed->id, 'is_active' => 0, 'per_page' => 2]))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 3)
        ->assertJsonPath('data.0.id', $prices[0]->id)->assertJsonPath('data.1.id', $prices[1]->id);
    expect($first->json('pagination.next_page_url'))->toContain('seed_id='.$seed->id, 'is_active=0', 'per_page=2', 'page=2');
    $this->getJson($first->json('pagination.next_page_url'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $prices[2]->id);
});

test('preview includes localized crop and variety names and reference package data', function () {
    $admin = AdminUser::factory()->create();
    $seed = priceSeed();
    $variety = CropVariety::factory()->for($seed->crop)->withNames('Variety', 'किस्म')->create(['is_active' => true]);
    $seed->crop->translations()->where('locale', 'hi')->update(['name' => 'सरसों']);
    $seed->forceFill(['crop_variety_id' => $variety->id, 'reference_pack_quantity' => '5.5000', 'reference_pack_unit_code' => 'kg'])->save();
    $price = SeedPrice::factory()->for($seed)->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/seed-prices/'.$price->id)->assertOk()->assertJsonPath('data.seed.crop_name', 'सरसों')
        ->assertJsonPath('data.seed.crop_variety_name', 'किस्म')->assertJsonPath('data.seed.reference_pack_quantity', '5.5000')
        ->assertJsonPath('data.seed.reference_pack_unit_code', 'kg')->assertJsonPath('data.seed.default_unit_code', 'g');
});

test('mapping update failure rolls back edited price fields and deleted district mappings', function () {
    Exceptions::fake();
    $admin = AdminUser::factory()->create();
    $price = SeedPrice::factory()->create(['region_scope' => 'specific']);
    $oldDistrict = priceDistrict();
    $newDistrict = priceDistrict('Ajmer', $oldDistrict->parent);
    $price->regions()->attach($oldDistrict);
    DB::unprepared("CREATE TRIGGER fail_price_region_update BEFORE INSERT ON seed_price_regions BEGIN SELECT RAISE(ABORT, 'Simulated mapping failure'); END");
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->patchJson('/api/admin/seed-prices/'.$price->id,
            ['package_price' => '999', 'region_ids' => [$newDistrict->id]])->assertInternalServerError();
        expect($price->fresh()->package_price)->toBe('200.0000');
        expect($price->regions()->pluck('regions.id')->all())->toBe([$oldDistrict->id]);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_price_region_update');
    }
});

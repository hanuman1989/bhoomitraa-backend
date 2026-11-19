<?php

use App\Models\CropSeedRate;
use App\Models\CropVariety;
use App\Models\CropYieldBenchmark;
use App\Models\FarmerProfile;
use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use App\Models\FertilizerPrice;
use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use App\Models\Seed;
use App\Models\SeedLot;
use App\Models\SeedPrice;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

require_once __DIR__.'/../FarmCropFixtures.php';

uses(LazilyRefreshDatabase::class);

function cropManagerSeed(array $context): Seed
{
    return Seed::factory()->completed()->for($context['crop'])->create(['crop_variety_id' => $context['variety']->id, 'is_active' => true,
        'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active(), 'default_unit_code' => 'kg']);
}

function cropManagerRate(array $context, array $overrides = []): CropSeedRate
{
    return CropSeedRate::factory()->create(array_replace(['crop_id' => $context['crop']->id, 'is_active' => true, 'rate_recommended' => '12.0000'], $overrides));
}

test('ranks district state country and explicit general rates without duplicating pivot matches', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    $general = cropManagerRate($context);
    $state = cropManagerRate($context, ['region_scope' => 'specific', 'rate_recommended' => 20]);
    $state->regions()->attach($context['state']);
    $country = cropManagerRate($context, ['region_scope' => 'specific', 'rate_recommended' => 30]);
    $country->regions()->attach($context['country']);
    $exact = cropManagerRate($context, ['region_scope' => 'specific', 'rate_recommended' => 10]);
    $exact->regions()->attach([$context['district']->id, $context['state']->id]);
    $unrelated = Region::factory()->under($context['state'])->create();
    cropManagerRate($context, ['region_scope' => 'specific'])->regions()->attach($unrelated);
    cropManagerRate($context, ['region_scope' => 'specific']);

    $response = $this->getJson('/api/account/crop-seed-rates?farm_id='.$context['farm']->id.'&crop_id='.$context['crop']->id.'&material_type=seed')
        ->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.id', $exact->id)
        ->assertJsonPath('data.0.match_type', 'exact_region')->assertJsonPath('pagination.total', 4);
    expect(array_column($response->json('data'), 'id'))->toBe([$exact->id, $state->id, $country->id, $general->id]);
});

test('uses only general rates when farm region is absent and never uses the residential profile', function () {
    $context = cropManagerContext();
    $profileDistrict = Region::factory()->under($context['state'])->create();
    FarmerProfile::factory()->for($context['user'])->create(['state_id' => $context['state']->id, 'district_id' => $profileDistrict->id]);
    $local = cropManagerRate($context, ['region_scope' => 'specific']);
    $local->regions()->attach($context['district']);
    $residential = cropManagerRate($context, ['region_scope' => 'specific']);
    $residential->regions()->attach($profileDistrict);
    $general = cropManagerRate($context);
    cropManagerDraft($this, $context);

    $this->getJson('/api/account/crop-seed-rates?farm_id='.$context['farm']->id.'&crop_id='.$context['crop']->id.'&material_type=seed')
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $local->id);
    $context['farm']->forceFill(['region_id' => null, 'state_id' => null, 'district_id' => null])->save();
    $this->getJson('/api/account/crop-seed-rates?farm_id='.$context['farm']->id.'&crop_id='.$context['crop']->id.'&material_type=seed')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $general->id);
});

test('excludes incompatible varieties seasons unknown conditions inactive records and unverified sources', function (string $case) {
    $context = cropManagerContext();
    cropManagerDraft($this, $context);
    $good = cropManagerRate($context);
    $overrides = match ($case) {
        'variety' => ['crop_variety_id' => CropVariety::factory()->for($context['crop'])->create()->id],
        'season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => true])->id],
        'irrigation' => ['irrigation_condition' => 'irrigated'],
        'inactive' => ['is_active' => false],
        'source' => ['source_id' => KnowledgeSource::factory()->create()->id],
        'scope' => ['region_scope' => 'unsupported'],
        'missing-pivots' => ['region_scope' => 'specific'],
    };
    cropManagerRate($context, $overrides);

    $this->getJson('/api/account/crop-seed-rates?farm_id='.$context['farm']->id.'&crop_id='.$context['crop']->id.'&crop_variety_id='.$context['variety']->id.'&season_id='.$context['season']->id.'&material_type=seed')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $good->id);
})->with(['variety', 'season', 'irrigation', 'inactive', 'source', 'scope', 'missing-pivots']);

test('returns ambiguity for conflicting equally applicable rates and allows custom quantities', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    cropManagerRate($context, ['rate_recommended' => 12]);
    cropManagerRate($context, ['rate_recommended' => 13]);
    $id = $draft['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertOk();

    $this->getJson('/api/account/farm-crops/'.$id.'/seed-recommendation?seed_id='.$seed->id)->assertOk()
        ->assertJsonPath('data.availability_status', 'ambiguous_recommendation')->assertJsonPath('data.recommended_quantity', null)->assertJsonCount(2, 'data.alternatives');
    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 2, 'seed_source' => 'catalogue', 'seed_id' => $seed->id,
        'sowing_date' => '2026-11-01', 'quantity_mode' => 'custom', 'seed_quantity' => 9, 'seed_quantity_unit' => 'kg'])->assertOk()->assertJsonPath('data.seed_sowing_details.seed_quantity', '9.0000');
});

test('calculates seed quantities with selected area package rounding and maturity ranges', function () {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    $rate = cropManagerRate($context, ['rate_min' => 10, 'rate_recommended' => 12, 'rate_max' => 14]);
    $price = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'package_quantity' => 10, 'package_price' => 450]);
    $id = $draft['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => 2, 'area_unit' => 'hectare'])->assertOk();

    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 2, 'seed_source' => 'catalogue', 'seed_id' => $seed->id,
        'sowing_date' => '2026-11-01', 'quantity_mode' => 'suggested', 'seed_price_id' => $price->id])->assertOk()
        ->assertJsonPath('data.seed_sowing_details.recommended_quantity', '24.0000')->assertJsonPath('data.seed_sowing_details.purchase.package_count', 3)
        ->assertJsonPath('data.seed_sowing_details.purchase.purchase_quantity', '30.0000')->assertJsonPath('data.expenses.estimated_seed_cost', '1350.00')
        ->assertJsonPath('data.expected_harvest_date_min', '2027-02-09')->assertJsonPath('data.expected_harvest_date_max', '2027-03-01')
        ->assertJsonPath('data.expected_harvest_date', null);
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'seed_quantity' => 24]);
});

test('returns 422 when a suggested quantity is outside storage precision', function (string $area, string $rate) {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    cropManagerRate($context, ['rate_recommended' => $rate]);
    $id = $draft['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => $area, 'area_unit' => 'hectare'])->assertOk();

    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 2, 'seed_source' => 'catalogue', 'seed_id' => $seed->id,
        'sowing_date' => '2026-11-01', 'quantity_mode' => 'suggested'])->assertUnprocessable()->assertJsonValidationErrors('seed_quantity');
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'revision' => 2, 'seed_quantity' => null]);
})->with(['rounds to zero' => ['0.00000001', '0.0001'], 'exceeds maximum' => ['5', '9999999999.9999']]);

test('rejects invalid selected seed prices with 422 instead of substituting another regions price', function (string $case) {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    $otherDistrict = Region::factory()->under($context['state'])->create();
    $otherSeed = cropManagerSeed($context);
    $overrides = match ($case) {
        'unrelated-region' => ['region_scope' => 'specific'],
        'expired' => ['effective_to' => '2026-11-15'],
        'future' => ['effective_from' => '2026-11-17'],
        'inactive' => ['is_active' => false],
        'wrong-product' => ['seed_id' => $otherSeed->id],
        'currency' => ['currency_code' => 'USD'],
        'zero-package' => ['package_quantity' => 0],
        'incompatible-unit' => ['package_unit_code' => 'plant'],
    };
    $price = SeedPrice::factory()->for($seed)->create(array_replace(['is_active' => true], $overrides));
    if ($case === 'unrelated-region') {
        $price->regions()->attach($otherDistrict);
    }
    $id = $draft['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertOk();

    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 2, 'seed_source' => 'catalogue', 'seed_id' => $seed->id,
        'sowing_date' => '2026-11-01', 'quantity_mode' => 'custom', 'seed_quantity' => 12, 'seed_quantity_unit' => 'kg', 'seed_price_id' => $price->id])
        ->assertUnprocessable()->assertJsonValidationErrors('seed_price_id');
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'revision' => 2, 'seed_id' => null]);
})->with(['unrelated-region', 'expired', 'future', 'inactive', 'wrong-product', 'currency', 'zero-package', 'incompatible-unit']);

test('lists regional price alternatives and prevents request region overrides of saved farm context', function () {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    $district = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'region_scope' => 'specific']);
    $district->regions()->attach([$context['district']->id, $context['state']->id]);
    $state = SeedPrice::factory()->for($seed)->create(['is_active' => true, 'region_scope' => 'specific']);
    $state->regions()->attach($context['state']);
    $general = SeedPrice::factory()->for($seed)->create(['is_active' => true]);
    $otherDistrict = Region::factory()->under($context['state'])->create();
    SeedPrice::factory()->for($seed)->create(['is_active' => true, 'region_scope' => 'specific'])->regions()->attach($otherDistrict);

    $this->getJson('/api/account/seed-prices?farm_id='.$context['farm']->id.'&seed_id='.$seed->id)->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $district->id)->assertJsonPath('data.1.id', $state->id)->assertJsonPath('data.2.id', $general->id);
    $this->getJson('/api/account/seed-prices?crop_cycle_id='.$draft['id'].'&seed_id='.$seed->id.'&region_id='.$otherDistrict->id)
        ->assertUnprocessable()->assertJsonValidationErrors('region_id');
});

test('returns nutrient requirements without inventing product allocation and saves regional fertilizer packages', function () {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $recommendation = FertilizerRecommendation::factory()->create(['crop_id' => $context['crop']->id, 'region_scope' => 'specific']);
    $recommendation->regions()->attach($context['district']);
    FertilizerRecommendationNutrient::factory()->for($recommendation)->create(['nutrient_code' => 'N', 'recommended_quantity' => 20]);
    $product = Fertilizer::factory()->completed()->create(['is_active' => true, 'verification_status' => 'verified']);
    FertilizerNutrient::factory()->for($product)->create(['nutrient_code' => 'N', 'content_value' => 46, 'content_unit_code' => 'percent_ww']);
    $price = FertilizerPrice::factory()->for($product)->create(['is_active' => true, 'package_quantity' => 10, 'package_price' => 50, 'region_scope' => 'specific']);
    $price->regions()->attach($context['state']);

    $this->getJson('/api/account/farm-crops/'.$id.'/fertilizer-recommendation')->assertOk()->assertJsonPath('data.nutrient_requirements.0.total_quantity', '20.0000')
        ->assertJsonPath('data.suggested_product_quantities', null)->assertJsonPath('data.recommendation.match_type', 'exact_region');
    $this->getJson('/api/account/fertilizer-prices?farm_id='.$context['farm']->id.'&fertilizer_id='.$product->id)->assertOk()->assertJsonPath('data.0.match_type', 'parent_region');
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 5, 'skipped' => false, 'fertilizers' => [[
        'fertilizer_id' => $product->id, 'fertilizer_recommendation_id' => $recommendation->id, 'quantity_mode' => 'custom',
        'planned_quantity' => 21, 'quantity_unit_code' => 'kg', 'fertilizer_price_id' => $price->id,
    ]]])->assertOk()->assertJsonPath('data.fertilizer_plan.selected_fertilizers.0.package_count', 3)
        ->assertJsonPath('data.expenses.estimated_fertilizer_cost', '150.00');
    $this->assertDatabaseHas('farm_crop_fertilizer_plans', ['farm_crop_cycle_id' => $id, 'planned_quantity' => 21, 'estimated_cost' => 150]);
});

test('calculates regional expected yield on review and saves exactly one accepted snapshot on activation', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $benchmark = CropYieldBenchmark::factory()->create(['crop_id' => $context['crop']->id, 'is_active' => true, 'region_scope' => 'specific',
        'yield_min_kg_ha' => 1000, 'yield_avg_kg_ha' => 1200, 'yield_max_kg_ha' => 1400, 'benchmark_type' => 'district_average']);
    $benchmark->regions()->attach($context['district']);
    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk()->assertJsonPath('data.expected_yield.estimated_avg_yield', '1200.0000');
    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk();
    $this->assertDatabaseCount('farm_crop_yield_estimates', 0);

    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 5])->assertOk();
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 5])->assertOk();
    $benchmark->update(['yield_avg_kg_ha' => 1300]);
    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk()->assertJsonPath('data.expected_yield.estimated_avg_yield', '1200.0000');

    $this->assertDatabaseCount('farm_crop_yield_estimates', 1);
});

test('marks saved regional estimates for review when prices or farm regions change', function () {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $seed = cropManagerSeed($context);
    $price = SeedPrice::factory()->for($seed)->create(['is_active' => true]);
    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 5, 'seed_source' => 'catalogue', 'seed_id' => $seed->id,
        'sowing_date' => '2026-11-01', 'quantity_mode' => 'custom', 'seed_quantity' => 12, 'seed_quantity_unit' => 'kg', 'seed_price_id' => $price->id])->assertOk();
    $price->update(['package_price' => 300]);

    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk()->assertJsonPath('data.wizard_progress.seed_sowing', 'needs_review')->assertJsonPath('data.can_finalize', false);
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 6])->assertUnprocessable()->assertJsonValidationErrors('wizard_progress.seed_sowing');
    $otherDistrict = Region::factory()->under($context['state'])->create();
    $this->patchJson('/api/account/farms/'.$context['farm']->id, ['district_id' => $otherDistrict->id])->assertOk();
    $this->getJson('/api/account/farm-crops/'.$id)->assertOk()->assertJsonPath('data.wizard_progress.seed_sowing', 'needs_review')->assertJsonPath('data.revision', 7);
});

test('filters seed lots by selected product verification and expiry without exposing internal reports', function () {
    $this->travelTo('2026-11-16 12:00:00');
    $context = cropManagerContext();
    cropManagerDraft($this, $context);
    $seed = cropManagerSeed($context);
    $good = SeedLot::factory()->for($seed)->create(['verification_status' => 'verified', 'is_active' => true, 'valid_until' => '2026-12-01', 'production_date' => '2026-01-01', 'test_date' => '2026-02-01']);
    SeedLot::factory()->for($seed)->create(['verification_status' => 'pending', 'is_active' => true]);
    SeedLot::factory()->for($seed)->create(['verification_status' => 'verified', 'is_active' => true, 'valid_until' => '2026-01-01']);

    $this->getJson('/api/account/seed-lots?seed_id='.$seed->id)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $good->id)->assertJsonMissingPath('data.0.report_file')->assertJsonMissingPath('data.0.verified_by');
});

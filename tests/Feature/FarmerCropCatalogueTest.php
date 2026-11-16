<?php

use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Seed;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

require_once __DIR__.'/../FarmCropFixtures.php';

uses(LazilyRefreshDatabase::class);

test('filters the exact recommendation flag for true false and omitted requests', function () {
    $context = cropManagerContext();
    $context['crop']->update(['is_recommendate' => true]);
    $ordinary = Crop::factory()->published('Rice', 'धान')->create(['is_recommendate' => false]);
    Crop::factory()->create(['is_recommendate' => true]);
    $this->withToken($context['user']->createToken('catalogue', ['frontend'])->plainTextToken);

    $this->getJson('/api/account/crops/list?is_recommendate=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $context['crop']->id);
    $this->getJson('/api/account/crops/list?is_recommendate=false')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ordinary->id);
    $this->getJson('/api/account/crops/list')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/account/crops/list?is_recommendate=maybe')->assertUnprocessable()->assertJsonValidationErrors('is_recommendate');
});

test('searches Hindi English and literal wildcards and sorts before pagination', function () {
    $context = cropManagerContext();
    $special = Crop::factory()->published('100% crop', 'विशेष फसल')->create();
    Crop::factory()->published('1000 crop', 'सामान्य फसल')->create();
    $this->withToken($context['user']->createToken('catalogue', ['frontend'])->plainTextToken);

    $this->getJson('/api/account/crops/list?search='.urlencode('विशेष'))->assertOk()->assertJsonPath('data.0.id', $special->id);
    $this->getJson('/api/account/crops/list?search='.urlencode('%'))->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/account/crops/list?per_page=1&sort_by=name')->assertOk()->assertJsonPath('pagination.total', 3)->assertJsonPath('data.0.id', $special->id);
    $this->getJson('/api/account/crops/list?sort_by=password')->assertUnprocessable()->assertJsonValidationErrors('sort_by');
    $this->getJson('/api/account/crops/list?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/api/account/crops/list?search=unmatched-xyz')->assertOk()->assertJsonPath('data', []);
});

test('lists seasons regions categories and only varieties belonging to the requested crop', function () {
    $context = cropManagerContext();
    CropVariety::factory()->withNames()->create(['is_active' => true]);
    $this->withToken($context['user']->createToken('catalogue', ['frontend'])->plainTextToken);

    $this->getJson('/api/account/seasons?search=Rabi')->assertOk()->assertJsonPath('data.0.id', $context['season']->id);
    $this->getJson('/api/account/regions?parent_id='.$context['state']->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Jaipur');
    $this->getJson('/api/account/crop-categories')->assertOk()->assertJsonPath('data.0.active_crops_count', 1);
    $this->getJson('/api/account/crop-varieties?crop_id='.$context['crop']->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $context['variety']->id);
    $this->getJson('/api/account/crop-varieties')->assertUnprocessable()->assertJsonValidationErrors('crop_id');
});

test('includes generic compatible seeds without requiring regional prices and excludes other varieties', function () {
    $context = cropManagerContext();
    $source = KnowledgeSource::factory()->active()->create();
    $generic = Seed::factory()->completed()->for($context['crop'])->create(['is_active' => true, 'verification_status' => 'verified', 'source_id' => $source->id]);
    $specific = Seed::factory()->completed()->for($context['crop'])->create(['crop_variety_id' => $context['variety']->id, 'is_active' => true, 'verification_status' => 'verified', 'source_id' => $source->id]);
    $otherVariety = CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true]);
    Seed::factory()->completed()->for($context['crop'])->create(['crop_variety_id' => $otherVariety->id, 'is_active' => true, 'verification_status' => 'verified', 'source_id' => $source->id]);
    $this->withToken($context['user']->createToken('catalogue', ['frontend'])->plainTextToken);

    $response = $this->getJson('/api/account/seeds?crop_id='.$context['crop']->id.'&crop_variety_id='.$context['variety']->id)->assertOk()->assertJsonCount(2, 'data');
    expect(array_column($response->json('data'), 'id'))->toEqualCanonicalizing([$generic->id, $specific->id]);
    $response->assertJsonMissingPath('data.0.requested_is_active')->assertJsonMissingPath('data.0.setup_status');
});

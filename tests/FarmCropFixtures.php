<?php

use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/** @return array{user: User, farm: Farm, crop: Crop, season: Season, variety: CropVariety, district: Region, state: Region, country: Region} */
function cropManagerContext(): array
{
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    $district = Region::factory()->under($state)->withEnglishName('Jaipur')->create();
    $user = User::factory()->create();
    $farm = Farm::factory()->for($user)->inDistrict($district)->create(['area' => '5.000', 'area_unit' => 'hectare']);
    $crop = Crop::factory()->published()->create();
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    $variety = CropVariety::factory()->for($crop)->withNames()->create(['is_active' => true, 'maturity_days_min' => 100, 'maturity_days_max' => 120]);
    $variety->knowledgeSources()->attach(KnowledgeSource::factory()->active()->create());

    return compact('user', 'farm', 'crop', 'season', 'variety', 'district', 'state', 'country');
}

/** @return array<string, mixed> */
function cropManagerSelection(array $context): array
{
    return ['farm_id' => $context['farm']->id, 'crop_id' => $context['crop']->id, 'season_id' => $context['season']->id,
        'crop_variety_id' => $context['variety']->id, 'sowing_date' => '2026-11-01'];
}

function cropManagerDraft(TestCase $test, array $context): array
{
    return $test->withToken($context['user']->createToken('crop-manager', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farm-crops', cropManagerSelection($context), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data');
}

/** A draft ready for Review & Save (revision 2): Crop Selection plus a manual-area boundary. */
function cropManagerReady(TestCase $test, array $context): array
{
    $draft = cropManagerDraft($test, $context);

    return $test->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => '1', 'area_unit' => 'hectare'])
        ->assertOk()->json('data');
}

/** @return array{type: string, coordinates: array} */
function cropManagerPolygon(float $west = 75.0, float $east = 75.002, float $south = 26.0, float $north = 26.002): array
{
    return ['type' => 'MultiPolygon', 'coordinates' => [[[
        [$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south],
    ]]]];
}

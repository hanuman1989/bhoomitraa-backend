<?php

use App\Models\AdminUser;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\FarmCropCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../FarmCropFixtures.php';

uses(LazilyRefreshDatabase::class);

test('creates one retry-safe draft with server controlled progress and authoritative farm region', function () {
    $context = cropManagerContext();
    $key = (string) Str::uuid();
    $token = $context['user']->createToken('crop', ['frontend'])->plainTextToken;
    $payload = [...cropManagerSelection($context), 'status' => 'active', 'completed_step' => 6, 'revision' => 100];
    $first = $this->withToken($token)->postJson('/api/account/farm-crops', $payload, ['Idempotency-Key' => $key])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.completed_step', 1)->assertJsonPath('data.next_step', 2)
        ->assertJsonPath('data.wizard_progress', ['crop_selection' => 'completed', 'boundary' => 'needs_review', 'review_save' => 'pending'])
        ->assertJsonPath('data.sowing_date', '2026-11-01')->assertJsonMissingPath('data.sowing_method')
        ->assertJsonPath('data.regional_context.region_id', $context['district']->id)->assertJsonPath('data.boundary_details.boundary', null);
    $this->postJson('/api/account/farm-crops', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson('/api/account/farm-crops', [...$payload, 'sowing_date' => '2026-11-02'], ['Idempotency-Key' => $key])->assertConflict();

    $this->assertDatabaseCount('farm_crop_cycles', 1);
    expect(array_keys($first->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('requires a valid sowing date and rejects every former seed soil fertilizer and sowing method input', function (array $input, string $field) {
    $context = cropManagerContext();

    $this->withToken($context['user']->createToken('crop', ['frontend'])->plainTextToken)
        ->postJson('/api/account/farm-crops', [...cropManagerSelection($context), ...$input], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('farm_crop_cycles', 0);
})->with([
    'missing sowing date' => [['sowing_date' => null], 'sowing_date'],
    'invalid sowing date' => [['sowing_date' => '01-11-2026'], 'sowing_date'],
    'sowing method' => [['sowing_method' => 'broadcasting'], 'sowing_method'],
    'seed product' => [['seed_id' => 1], 'seed_id'],
    'seed quantity' => [['seed_quantity' => '25'], 'seed_quantity'],
    'seed source' => [['seed_source' => 'farm_saved'], 'seed_source'],
    'soil assessment' => [['assessment_mode' => 'skipped'], 'assessment_mode'],
    'fertilizer plan' => [['fertilizers' => [['custom_name' => 'Urea', 'planned_quantity' => '10']]], 'fertilizers'],
]);

test('calculates the harvest range from the sowing date and verified variety maturity', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);

    expect($draft['maturity'])->toBe(['maturity_status' => 'available', 'maturity_days_min' => 100, 'maturity_days_max' => 120,
        'expected_harvest_date' => null, 'expected_harvest_date_min' => '2027-02-09', 'expected_harvest_date_max' => '2027-03-01']);
    $saved = FarmCropCycle::findOrFail($draft['id']);
    expect($saved->expected_harvest_date)->toBeNull();
    expect($saved->expected_harvest_date_min->toDateString())->toBe('2027-02-09');
    expect($saved->expected_harvest_date_max->toDateString())->toBe('2027-03-01');
    expect($saved->planning_snapshot['maturity']['maturity_status'])->toBe('available');

    $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-1', [...cropManagerSelection($context), 'sowing_date' => '2026-12-01', 'revision' => 1])
        ->assertOk()->assertJsonPath('data.maturity.expected_harvest_date_min', '2027-03-11')->assertJsonPath('data.maturity.expected_harvest_date_max', '2027-03-31');
});

test('saves the crop without a maturity estimate when the variety range is unverified', function () {
    $context = cropManagerContext();
    $unverified = CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true, 'maturity_days_min' => 90, 'maturity_days_max' => 95]);
    $draft = cropManagerDraft($this, $context);
    $id = $draft['id'];

    $this->putJson('/api/account/farm-crops/'.$id.'/step-1', [...cropManagerSelection($context), 'crop_variety_id' => $unverified->id, 'revision' => 1])
        ->assertOk()->assertJsonPath('data.maturity.maturity_status', 'unavailable')->assertJsonPath('data.expected_harvest_date_min', null)
        ->assertJsonPath('data.sowing_date', '2026-11-01');
    $this->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 2, 'boundary_source' => 'manual_area', 'cultivated_area' => '1', 'area_unit' => 'hectare'])
        ->assertOk()->assertJsonPath('data.can_finalize', true);
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 3])->assertOk()->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.maturity.maturity_status', 'unavailable');
});

test('activates after crop selection and boundary only and creates no seed soil fertilizer yield jobs or notifications', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    Queue::fake();
    Notification::fake();

    $this->getJson('/api/account/farm-crops/'.$ready['id'])->assertOk()->assertJsonPath('data.next_step', 3)
        ->assertJsonPath('data.can_finalize', true)->assertJsonPath('data.validation_issues', [])
        ->assertJsonMissingPath('data.seed_sowing_details')->assertJsonMissingPath('data.soil_assessment')
        ->assertJsonMissingPath('data.fertilizer_plan')->assertJsonMissingPath('data.expenses')->assertJsonMissingPath('data.expected_yield');
    $this->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 2])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.completed_step', 3)->assertJsonPath('data.next_step', null)
        ->assertJsonPath('data.wizard_progress.review_save', 'completed')->assertJsonPath('data.timeline_generated', false);
    $this->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $ready['id'], 'status' => 'active', 'revision' => 3, 'completed_step' => 3,
        'sowing_method' => null, 'seed_id' => null, 'seed_quantity' => null]);
    foreach (['farm_crop_soil_assessments', 'soil_tests', 'farm_crop_fertilizer_plans', 'farm_crop_yield_estimates'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

test('returns 409 on stale revisions and 422 on unfinished activation without changing the crop', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);

    $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 99, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertConflict();
    $this->postJson('/api/account/farm-crops/'.$draft['id'].'/finalize', ['revision' => 1])->assertUnprocessable()
        ->assertJsonValidationErrors(['wizard_progress.boundary', 'cultivated_area'])->assertJsonMissingValidationErrors(['sowing_date', 'wizard_progress.seed_sowing']);

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $draft['id'], 'revision' => 1, 'status' => 'draft', 'cultivated_area' => null]);
});

test('returns 401 for anonymous requests and rejects admin tokens with 401', function () {
    $this->getJson('/api/account/farm-crops')->assertUnauthorized();
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('admin', ['admin'])->plainTextToken)->getJson('/api/account/farm-crops')->assertUnauthorized();
});

test('returns 404 for another farmers crop farm report and exclusion context', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);
    $other = User::factory()->create();
    app('auth')->forgetGuards();
    $this->withToken($other->createToken('other', ['frontend'])->plainTextToken)
        ->getJson('/api/account/farm-crops/'.$draft['id'])->assertNotFound();
    $this->getJson('/api/account/farms/'.$context['farm']->id.'/crop-boundaries')->assertNotFound();
    $this->deleteJson('/api/account/farm-crops/'.$draft['id'])->assertNotFound();
    $this->getJson('/api/account/farm-crops/'.$draft['id'].'/soil-report')->assertNotFound();

    $this->assertDatabaseCount('farm_crop_cycles', 1);
});

test('removes the former seed soil and fertilizer wizard endpoints', function (string $method, string $path) {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);

    $this->json($method, '/api/account/farm-crops/'.$ready['id'].$path, ['revision' => 2])->assertNotFound();
})->with([
    'seed and sowing' => ['PUT', '/step-3'], 'soil assessment' => ['POST', '/step-4'], 'fertilizer planning' => ['PUT', '/step-5'],
    'seed recommendation' => ['GET', '/seed-recommendation'], 'fertilizer recommendation' => ['GET', '/fertilizer-recommendation'],
]);

test('keeps active crops unchanged while editing and applies completed edits atomically', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 2])->assertOk();

    $this->putJson('/api/account/farm-crops/'.$id.'/step-1', [...cropManagerSelection($context), 'sowing_date' => '2026-12-01', 'revision' => 3])->assertOk()
        ->assertJsonPath('data.has_pending_edit', true)->assertJsonPath('data.revision', 4)
        ->assertJsonPath('data.next_step', 3)->assertJsonPath('data.wizard_progress.review_save', 'pending');
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'revision' => 3]);
    expect(FarmCropCycle::findOrFail($id)->sowing_date->toDateString())->toBe('2026-11-01');
    $this->getJson('/api/account/farm-crops/'.$id)->assertOk()->assertJsonPath('data.sowing_date', '2026-12-01');
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 4])->assertOk()->assertJsonPath('data.sowing_date', '2026-12-01')
        ->assertJsonPath('data.maturity.expected_harvest_date_min', '2027-03-11');

    $this->assertDatabaseCount('farm_crop_edit_drafts', 0);
    expect(FarmCropCycle::findOrFail($id)->sowing_date->toDateString())->toBe('2026-12-01');
    expect(FarmCropCycle::findOrFail($id)->planning_snapshot['history'])->toHaveCount(1);
});

test('discards pending active edits and cancels the live crop on delete', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 2])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-1', [...cropManagerSelection($context), 'sowing_date' => '2026-12-15', 'revision' => 3])->assertOk();
    $this->deleteJson('/api/account/farm-crops/'.$id.'/edit-draft', ['revision' => 4])->assertOk()->assertJsonPath('data.revision', 5)
        ->assertJsonPath('data.sowing_date', '2026-11-01')->assertJsonPath('data.has_pending_edit', false);
    $this->deleteJson('/api/account/farm-crops/'.$id)->assertOk();

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'status' => 'cancelled']);
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.active_crops', 0)->assertJsonPath('data.cancelled_crops', 1);
});

test('changing the farm clears only the boundary and keeps the selection and sowing date', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $otherFarm = Farm::factory()->for($context['user'])->inDistrict($context['district'])->create(['area' => '3.000', 'area_unit' => 'hectare']);

    $this->putJson('/api/account/farm-crops/'.$ready['id'].'/step-1', [...cropManagerSelection($context), 'farm_id' => $otherFarm->id, 'revision' => 2])
        ->assertOk()->assertJsonPath('data.wizard_progress.boundary', 'needs_review')->assertJsonPath('data.next_step', 2)
        ->assertJsonPath('data.cultivated_area', null)->assertJsonPath('data.sowing_date', '2026-11-01')->assertJsonPath('data.can_finalize', false);
});

test('deletes drafts and leaves all master records intact', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);

    $this->deleteJson('/api/account/farm-crops/'.$ready['id'])->assertOk();

    $this->assertDatabaseCount('farm_crop_cycles', 0);
    $this->assertModelExists($context['crop']);
    $this->assertModelExists($context['farm']);
    $this->assertModelExists($context['variety']);
});

test('excludes drafts from cultivated summaries and blocks unverifiable second manual allocations', function () {
    $context = cropManagerContext();
    $first = cropManagerReady($this, $context);
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.total_cultivated_area', '0');
    $this->postJson('/api/account/farm-crops/'.$first['id'].'/finalize', ['revision' => 2])->assertOk();
    $second = cropManagerDraft($this, $context);
    $this->putJson('/api/account/farm-crops/'.$second['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertConflict();
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.total_cultivated_area', '1');
    $this->getJson('/api/account/farm-crops/map?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/account/farms/'.$context['farm']->id.'/status', ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
});

test('keeps the review current when the farmer changes only the display locale', function () {
    $context = cropManagerContext();
    $context['district']->translations()->create(['locale' => 'hi', 'name' => 'जयपुर']);
    $ready = cropManagerReady($this, $context);

    $this->withHeader('X-Locale', 'hi')->getJson('/api/account/farm-crops/'.$ready['id'].'/review')->assertOk()
        ->assertJsonPath('data.can_finalize', true)->assertJsonPath('data.regional_context.applicable_regions.0.name', 'जयपुर')
        ->assertJsonPath('data.wizard_progress.boundary', 'completed');
});

test('normalizes former six-step crops and preserves their seed soil and fertilizer records when edited', function () {
    $context = cropManagerContext();
    $cycle = FarmCropCycle::factory()->for($context['farm'])->create([
        'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id, 'season_id' => $context['season']->id,
        'created_by' => $context['user']->id, 'status' => 'active', 'revision' => 6, 'completed_step' => 6,
        'sowing_method' => 'broadcasting', 'sowing_date' => '2026-11-01', 'seed_source' => 'farm_saved', 'seed_quantity' => '25',
        'seed_quantity_unit' => 'kg', 'boundary_source' => 'manual_area', 'cultivated_area' => '1', 'area_unit' => 'hectare',
        'wizard_progress' => ['crop_selection' => 'completed', 'boundary' => 'completed', 'seed_sowing' => 'completed',
            'soil_assessment' => 'skipped', 'fertilizer_planning' => 'completed'],
        'planning_snapshot' => ['seed' => ['maturity' => ['maturity_status' => 'unavailable']], 'expenses' => ['known_subtotal' => '100.00']],
    ]);
    $cycle->forceFill(['finalized_at' => now()])->save();
    $cycle->soilAssessments()->create(['fertile_capacity' => 'unknown', 'assessment_source' => 'farmer']);
    $cycle->fertilizerPlans()->create(['custom_name' => 'Farmyard manure', 'quantity_mode' => 'custom', 'planned_quantity' => '5', 'quantity_unit_code' => 'kg']);
    $token = $context['user']->createToken('crop', ['frontend'])->plainTextToken;

    $this->withToken($token)->getJson('/api/account/farm-crops/'.$cycle->id)->assertOk()
        ->assertJsonPath('data.wizard_progress', ['crop_selection' => 'completed', 'boundary' => 'completed', 'review_save' => 'completed'])
        ->assertJsonPath('data.completed_step', 3)->assertJsonPath('data.next_step', null)->assertJsonPath('data.maturity.maturity_status', 'unavailable');
    $this->putJson('/api/account/farm-crops/'.$cycle->id.'/step-1', [...cropManagerSelection($context), 'sowing_date' => '2026-11-10', 'revision' => 6])->assertOk()
        ->assertJsonPath('data.next_step', 3);
    $this->postJson('/api/account/farm-crops/'.$cycle->id.'/finalize', ['revision' => 7])->assertOk()->assertJsonPath('data.completed_step', 3);

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $cycle->id, 'sowing_method' => 'broadcasting',
        'seed_source' => 'farm_saved', 'seed_quantity' => 25, 'seed_quantity_unit' => 'kg']);
    expect(FarmCropCycle::findOrFail($cycle->id)->sowing_date->toDateString())->toBe('2026-11-10');
    $this->assertDatabaseCount('farm_crop_soil_assessments', 1);
    $this->assertDatabaseCount('farm_crop_fertilizer_plans', 1);
    expect(FarmCropCycle::findOrFail($cycle->id)->planning_snapshot['history'][0]['expenses'])->toBe(['known_subtotal' => '100.00']);
});

test('resumes a former six-step draft on the current steps without its old seed soil and fertilizer progress', function () {
    $context = cropManagerContext();
    $cycle = FarmCropCycle::factory()->for($context['farm'])->create([
        'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id, 'season_id' => $context['season']->id,
        'created_by' => $context['user']->id, 'status' => 'draft', 'revision' => 3, 'completed_step' => 3, 'sowing_date' => '2026-11-01',
        'boundary_source' => 'manual_area', 'cultivated_area' => '1', 'area_unit' => 'hectare',
        'wizard_progress' => ['crop_selection' => 'completed', 'boundary' => 'completed', 'seed_sowing' => 'completed',
            'soil_assessment' => 'pending', 'fertilizer_planning' => 'pending'],
    ]);
    $token = $context['user']->createToken('crop', ['frontend'])->plainTextToken;

    $this->withToken($token)->getJson('/api/account/farm-crops/'.$cycle->id)->assertOk()
        ->assertJsonPath('data.wizard_progress', ['crop_selection' => 'completed', 'boundary' => 'completed', 'review_save' => 'pending'])
        ->assertJsonPath('data.completed_step', 2)->assertJsonPath('data.next_step', 3)->assertJsonPath('data.can_finalize', true);
    $this->postJson('/api/account/farm-crops/'.$cycle->id.'/finalize', ['revision' => 3])->assertOk()->assertJsonPath('data.status', 'active');
});

test('returns 422 for invalid GeoJSON before persisting any boundary', function (array $boundary) {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);

    $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual', 'boundary' => $boundary])
        ->assertUnprocessable()->assertJsonValidationErrors('boundary');
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $draft['id'], 'revision' => 1, 'boundary' => null]);
})->with([
    'invalid longitude' => [['type' => 'MultiPolygon', 'coordinates' => [[[[181, 26], [75, 26], [75, 27], [181, 26]]]]]],
    'unclosed ring' => [['type' => 'MultiPolygon', 'coordinates' => [[[[75, 26], [75.1, 26], [75, 26.1], [75.2, 26.2]]]]]],
    'wrong geometry type' => [['type' => 'Polygon', 'coordinates' => []]],
    'repeated vertices' => [['type' => 'MultiPolygon', 'coordinates' => [[[[75, 26], [75.1, 26], [75.1, 26], [75, 26]]]]]],
]);

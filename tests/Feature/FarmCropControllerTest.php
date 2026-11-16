<?php

use App\Models\AdminUser;
use App\Models\CropVariety;
use App\Models\FarmCropCycle;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        ->assertJsonPath('data.regional_context.region_id', $context['district']->id)->assertJsonPath('data.boundary_details.boundary', null);
    $this->postJson('/api/account/farm-crops', $payload, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
    $this->postJson('/api/account/farm-crops', [...$payload, 'sowing_method' => 'line_sowing'], ['Idempotency-Key' => $key])->assertConflict();

    $this->assertDatabaseCount('farm_crop_cycles', 1);
    expect(array_keys($first->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('resumes saved steps activates once and creates no jobs or notifications', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    Queue::fake();
    Notification::fake();

    $this->getJson('/api/account/farm-crops/'.$ready['id'])->assertOk()->assertJsonPath('data.next_step', 6)
        ->assertJsonPath('data.soil_assessment.assessment_mode', 'skipped')->assertJsonPath('data.can_finalize', true);
    $this->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 5])->assertOk()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.completed_step', 6)->assertJsonPath('data.timeline_generated', false);
    $this->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 5])->assertOk()->assertJsonPath('data.revision', 6);

    $this->assertDatabaseCount('farm_crop_cycles', 1);
    $this->assertDatabaseCount('farm_crop_yield_estimates', 0);
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $ready['id'], 'status' => 'active', 'revision' => 6]);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

test('returns 409 on stale revisions and 422 on unfinished activation without changing the crop', function () {
    $context = cropManagerContext();
    $draft = cropManagerDraft($this, $context);

    $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 99, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertConflict();
    $this->postJson('/api/account/farm-crops/'.$draft['id'].'/finalize', ['revision' => 1])->assertUnprocessable()->assertJsonValidationErrors(['wizard_progress.boundary', 'sowing_date']);

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

test('keeps active crops unchanged while editing and applies completed edits atomically', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 5])->assertOk();

    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 6, 'seed_source' => 'farm_saved', 'sowing_date' => '2026-12-01',
        'quantity_mode' => 'custom', 'seed_quantity' => 30, 'seed_quantity_unit' => 'kg', 'manual_estimated_cost' => 200])->assertOk()
        ->assertJsonPath('data.has_pending_edit', true)->assertJsonPath('data.revision', 7);
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'sowing_date' => '2026-11-01 00:00:00', 'revision' => 6]);
    $this->getJson('/api/account/farm-crops/'.$id)->assertOk()->assertJsonPath('data.sowing_date', '2026-12-01');
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 7])->assertOk()->assertJsonPath('data.sowing_date', '2026-12-01');

    $this->assertDatabaseCount('farm_crop_edit_drafts', 0);
    expect(FarmCropCycle::findOrFail($id)->sowing_date->toDateString())->toBe('2026-12-01');
});

test('discards pending active edits and preserves historical seed estimates after cancellation', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 5])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 6, 'assessment_mode' => 'basic', 'fertile_capacity' => 'unknown'])->assertOk();
    $this->deleteJson('/api/account/farm-crops/'.$id.'/edit-draft', ['revision' => 7])->assertOk()->assertJsonPath('data.revision', 8);
    $this->deleteJson('/api/account/farm-crops/'.$id)->assertOk();

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'status' => 'cancelled']);
    expect(FarmCropCycle::findOrFail($id)->planning_snapshot['seed']['purchase']['estimated_cost'])->toBe('100.0000');
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.active_crops', 0)->assertJsonPath('data.cancelled_crops', 1);
});

test('invalidates downstream reviews when area changes while preserving custom seed quantities', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);

    $this->putJson('/api/account/farm-crops/'.$ready['id'].'/step-2', ['revision' => 5, 'boundary_source' => 'manual_area', 'cultivated_area' => 2, 'area_unit' => 'hectare'])
        ->assertOk()->assertJsonPath('data.wizard_progress.seed_sowing', 'needs_review')->assertJsonPath('data.seed_sowing_details.seed_quantity', '25.0000')
        ->assertJsonPath('data.can_finalize', false);

    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $ready['id'], 'seed_quantity' => 25, 'status' => 'draft']);
});

test('saves basic soil assessment once and distinguishes unknown from zero costs', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'basic', 'fertile_capacity' => 'unknown',
        'soil_moisture' => 'normal', 'drainage_condition' => 'unknown', 'water_availability' => 'limited'])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 6, 'assessment_mode' => 'basic', 'fertile_capacity' => 'good'])->assertOk()
        ->assertJsonPath('data.expenses.component_states.estimated_soil_test_cost', 'not_applicable')
        ->assertJsonPath('data.expenses.total_estimated_cost', null)->assertJsonPath('data.expenses.known_subtotal', '100.00');

    $this->assertDatabaseCount('farm_crop_soil_assessments', 1);
});

test('honors a declared basic assessment expense instead of replacing it with zero', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);

    $this->putJson('/api/account/farm-crops/'.$ready['id'].'/step-4', ['revision' => 5, 'assessment_mode' => 'basic', 'estimated_cost' => '75.50'])->assertOk()
        ->assertJsonPath('data.expenses.estimated_soil_test_cost', '75.50')->assertJsonPath('data.expenses.known_subtotal', '175.50')
        ->assertJsonPath('data.expenses.component_states.estimated_soil_test_cost', 'known');
});

test('saves unverified lab measurements with their units and replaces reports after successful saves', function () {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-20',
        'soil_ph' => 7.2, 'nitrogen' => 12, 'measurement_units' => ['nitrogen' => 'mg/kg'], 'report' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf'),
        'verified_at' => '2020-01-01', 'verification_status' => 'verified'])->assertOk()->assertJsonPath('data.soil_assessment.soil_test.measurement_units.nitrogen', 'mg/kg');
    $test = FarmCropCycle::findOrFail($id)->soilTests()->sole();
    $oldPath = $test->report_file;
    $this->getJson('/api/account/farm-crops/'.$id.'/soil-report')->assertOk();
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 6, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-21',
        'report' => UploadedFile::fake()->create('new.pdf', 20, 'application/pdf')])->assertOk();
    Storage::disk('private')->assertMissing($oldPath);
    Storage::disk('private')->assertExists($test->fresh()->report_file);
    expect($test->fresh()->verification_status)->toBe('pending');
    $this->assertDatabaseCount('soil_tests', 1);
});

test('returns 422 for invalid laboratory values units and upload types', function (array $override, string $field) {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);

    $this->postJson('/api/account/farm-crops/'.$ready['id'].'/step-4', array_replace(['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-20'], $override))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('soil_tests', 0);
})->with([
    'invalid pH' => [['soil_ph' => 15], 'soil_ph'],
    'missing unit' => [['nitrogen' => 10], 'measurement_units.nitrogen'],
    'negative nutrient' => [['zinc' => -1, 'measurement_units' => ['zinc' => 'ppm']], 'zinc'],
    'unsupported unit' => [['potassium' => 1, 'measurement_units' => ['potassium' => 'bananas']], 'measurement_units.potassium'],
    'no measurements' => [[], 'soil_test'],
]);

test('cleans failed report uploads while preserving the previous saved report', function () {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $payload = ['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-20',
        'report' => UploadedFile::fake()->create('original.pdf', 10, 'application/pdf')];
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', $payload)->assertOk();
    $test = FarmCropCycle::findOrFail($id)->soilTests()->sole();
    $original = $test->report_file;
    DB::unprepared("CREATE TRIGGER fail_crop_report BEFORE UPDATE ON soil_tests BEGIN SELECT RAISE(ABORT, 'Test report failure'); END");
    try {
        $this->postJson('/api/account/farm-crops/'.$id.'/step-4', array_replace($payload, ['revision' => 6,
            'report' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')]))->assertServerError();
        Storage::disk('private')->assertExists($original);
        expect(Storage::disk('private')->allFiles('soil-reports'))->toHaveCount(1);
        expect($test->fresh()->report_file)->toBe($original);
    } finally {
        DB::unprepared('DROP TRIGGER fail_crop_report');
    }
});

test('keeps old soil reports until the outermost replacement transaction commits', function () {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $payload = ['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-20',
        'report' => UploadedFile::fake()->create('original.pdf', 10, 'application/pdf')];
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', $payload)->assertOk();
    $original = FarmCropCycle::findOrFail($id)->soilTests()->sole()->report_file;
    $level = DB::transactionLevel();
    DB::beginTransaction();
    try {
        $this->postJson('/api/account/farm-crops/'.$id.'/step-4', array_replace($payload, ['revision' => 6,
            'report' => UploadedFile::fake()->create('replacement.pdf', 10, 'application/pdf')]))->assertOk();
        Storage::disk('private')->assertExists($original);
        DB::commit();
        Storage::disk('private')->assertMissing($original);
    } finally {
        while (DB::transactionLevel() > $level) {
            DB::rollBack();
        }
    }
});

test('synchronizes multiple manual fertilizer quantities and calculates complete saved expenses', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'basic'])->assertOk();
    $rows = [
        ['custom_name' => 'My compost', 'quantity_mode' => 'custom', 'planned_quantity' => 100, 'quantity_unit_code' => 'kg', 'manual_estimated_cost' => 500],
        ['custom_name' => 'My amendment', 'quantity_mode' => 'custom', 'planned_quantity' => 10, 'quantity_unit_code' => 'kg', 'manual_estimated_cost' => 0],
    ];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 6, 'skipped' => false, 'fertilizers' => $rows])->assertOk()
        ->assertJsonPath('data.expenses.total_estimated_cost', '600.00')->assertJsonPath('data.expenses.is_complete', true);
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 7, 'skipped' => false, 'fertilizers' => [$rows[0]]])->assertOk();

    $this->assertDatabaseCount('farm_crop_fertilizer_plans', 1);
    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk()->assertJsonPath('data.expenses.total_estimated_cost', '600.00');
    $this->assertDatabaseCount('farm_crop_yield_estimates', 0);
});

test('deletes draft reports and leaves all master records intact', function () {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-10-20',
        'report' => UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')])->assertOk();
    $path = FarmCropCycle::findOrFail($id)->soilTests()->sole()->report_file;

    $this->deleteJson('/api/account/farm-crops/'.$id)->assertOk();

    Storage::disk('private')->assertMissing($path);
    $this->assertDatabaseCount('soil_tests', 0);
    $this->assertDatabaseCount('farm_crop_cycles', 0);
    $this->assertModelExists($context['crop']);
    $this->assertModelExists($context['farm']);
});

test('excludes drafts from cultivated summaries and blocks unverifiable second manual allocations', function () {
    $context = cropManagerContext();
    $first = cropManagerReady($this, $context);
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.total_cultivated_area', '0');
    $this->postJson('/api/account/farm-crops/'.$first['id'].'/finalize', ['revision' => 5])->assertOk();
    $second = cropManagerDraft($this, $context);
    $this->putJson('/api/account/farm-crops/'.$second['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual_area', 'cultivated_area' => 1, 'area_unit' => 'hectare'])->assertConflict();
    $this->getJson('/api/account/farm-crops/summary')->assertOk()->assertJsonPath('data.total_cultivated_area', '1');
    $this->getJson('/api/account/farm-crops/map?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/account/farms/'.$context['farm']->id.'/status', ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors('is_active');
});

test('preserves custom quantities and sowing dates when a variety change requires dependent review', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $variety = CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true]);

    $this->putJson('/api/account/farm-crops/'.$ready['id'].'/step-1', [...cropManagerSelection($context), 'crop_variety_id' => $variety->id, 'revision' => 5])
        ->assertOk()->assertJsonPath('data.seed_sowing_details.seed_quantity', '25.0000')->assertJsonPath('data.sowing_date', '2026-11-01')
        ->assertJsonPath('data.wizard_progress.seed_sowing', 'needs_review')->assertJsonPath('data.expenses.estimated_seed_cost', null);
});

test('keeps regional plans current when the farmer changes only the display locale', function () {
    $context = cropManagerContext();
    $context['district']->translations()->create(['locale' => 'hi', 'name' => 'जयपुर']);
    $ready = cropManagerReady($this, $context);

    $this->withHeader('X-Locale', 'hi')->getJson('/api/account/farm-crops/'.$ready['id'].'/review')->assertOk()
        ->assertJsonPath('data.can_finalize', true)->assertJsonPath('data.regional_context.applicable_regions.0.name', 'जयपुर')
        ->assertJsonPath('data.wizard_progress.seed_sowing', 'completed');
});

test('preserves accepted basic soil records when an active crop assessment is skipped', function () {
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'basic', 'fertile_capacity' => 'poor'])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 6, 'skipped' => true])->assertOk();
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 7])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 8, 'assessment_mode' => 'skipped'])->assertOk();
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 9, 'skipped' => true])->assertOk();

    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 10])->assertOk()->assertJsonPath('data.soil_assessment.basic_assessment', null);

    $this->assertDatabaseHas('farm_crop_soil_assessments', ['farm_crop_cycle_id' => $id, 'fertile_capacity' => 'poor']);
});

test('returns 422 when a fertilizer plan ID belongs to a different crop', function () {
    $context = cropManagerContext();
    $first = cropManagerReady($this, $context);
    $this->putJson('/api/account/farm-crops/'.$first['id'].'/step-5', ['revision' => 5, 'skipped' => false, 'fertilizers' => [[
        'custom_name' => 'Compost', 'quantity_mode' => 'custom', 'planned_quantity' => 10, 'quantity_unit_code' => 'kg',
    ]]])->assertOk();
    $planId = FarmCropCycle::findOrFail($first['id'])->fertilizerPlans()->sole()->id;
    $second = cropManagerReady($this, $context);

    $this->putJson('/api/account/farm-crops/'.$second['id'].'/step-5', ['revision' => 5, 'skipped' => false, 'fertilizers' => [[
        'id' => $planId, 'custom_name' => 'Changed', 'quantity_mode' => 'custom', 'planned_quantity' => 1, 'quantity_unit_code' => 'kg',
    ]]])->assertUnprocessable()->assertJsonValidationErrors('fertilizers.0.id');
    $this->assertDatabaseHas('farm_crop_fertilizer_plans', ['id' => $planId, 'custom_name' => 'Compost', 'planned_quantity' => 10]);
});

test('retains historical soil reports while discarding unaccepted active edit uploads', function () {
    Storage::fake('private');
    $context = cropManagerContext();
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 5, 'assessment_mode' => 'soil_test', 'test_date' => '2026-11-01',
        'report' => UploadedFile::fake()->create('original.pdf', 10, 'application/pdf')])->assertOk();
    $original = FarmCropCycle::findOrFail($id)->soilTests()->sole()->report_file;
    $this->putJson('/api/account/farm-crops/'.$id.'/step-5', ['revision' => 6, 'skipped' => true])->assertOk();
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 7])->assertOk();
    $this->postJson('/api/account/farm-crops/'.$id.'/step-4', ['revision' => 8, 'assessment_mode' => 'soil_test', 'test_date' => '2026-11-02',
        'report' => UploadedFile::fake()->create('pending.pdf', 10, 'application/pdf')])->assertOk();
    $pending = FarmCropCycle::findOrFail($id)->editDraft->draft_data['soil_test']['report_file'];

    $this->deleteJson('/api/account/farm-crops/'.$id.'/edit-draft', ['revision' => 9])->assertOk();

    Storage::disk('private')->assertExists($original);
    Storage::disk('private')->assertMissing($pending);
    $this->assertDatabaseCount('soil_tests', 1);
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

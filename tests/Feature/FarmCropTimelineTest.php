<?php

use App\Models\ActivityCategory;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\FarmCropActivity;
use App\Models\FarmCropCycle;
use App\Models\KnowledgeSource;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

require_once __DIR__.'/../FarmCropFixtures.php';

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // 24 Nov 2026 in India; the crop is sown on 1 Nov 2026.
    $this->travelTo('2026-11-24 06:00:00');
});

/** A published crop with one timed growth stage (days 20–45), an irrigation category and a source. */
function cropTimelineContext(): array
{
    $context = cropManagerContext();
    $context['stage'] = CropStage::factory()->for($context['crop'])->withNames('Tillering', 'कल्ले निकलना')->create(['is_active' => true, 'sequence' => 3]);
    $context['category'] = ActivityCategory::factory()->withNames('Irrigation', 'सिंचाई')->create(['is_active' => true, 'icon' => 'DropletsIcon']);
    $context['source'] = KnowledgeSource::factory()->active()->create();
    $timeline = CropStageTimeline::factory()->for($context['stage'], 'cropStage')
        ->create(['is_active' => true, 'days_after_sowing_min' => 20, 'days_after_sowing_max' => 45]);
    $timeline->knowledgeSources()->sync([$context['source']->id]);

    return $context;
}

function cropTimelineTemplate(array $context, array $overrides = [], string $title = 'Field Preparation', string $hindi = 'खेत की तैयारी'): CropActivityTemplate
{
    $template = CropActivityTemplate::factory()->for($context['crop'], 'crop')->for($context['category'], 'category')
        ->withTitles($title, $hindi)->create(array_replace(['season_scope' => 'all', 'is_active' => true], $overrides));
    $template->knowledgeSources()->sync([$context['source']->id]);

    return $template;
}

/** Saves the crop (draft → active) and returns its id. */
function cropTimelineActiveCrop(TestCase $test, array $context): int
{
    $ready = cropManagerReady($test, $context);
    $test->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 2])->assertOk();

    return $ready['id'];
}

/** Before sowing, growth-stage based, after harvest, and one stage-based template whose stage has no window. */
function cropTimelineStandardTemplates(array $context): array
{
    $untimedStage = CropStage::factory()->for($context['crop'])->withNames('Booting', 'गाभा')->create(['is_active' => true, 'sequence' => 5]);

    return [
        'before' => cropTimelineTemplate($context, ['schedule_type' => 'before_sowing', 'start_offset_days' => 2, 'end_offset_days' => 10,
            'allow_rescheduling' => true, 'priority' => 'high', 'smart_actions' => ['weather_forecast', 'soil_test']]),
        'stage' => cropTimelineTemplate($context, ['schedule_type' => 'after_sowing', 'schedule_basis' => 'growth_stage',
            'crop_stage_id' => $context['stage']->id, 'start_offset_days' => null, 'end_offset_days' => null], 'First Irrigation', 'पहली सिंचाई'),
        'harvest' => cropTimelineTemplate($context, ['schedule_type' => 'after_harvesting', 'start_offset_days' => 0, 'end_offset_days' => 5], 'Storage', 'भंडारण'),
        'untimed' => cropTimelineTemplate($context, ['schedule_type' => 'after_sowing', 'schedule_basis' => 'growth_stage',
            'crop_stage_id' => $untimedStage->id, 'start_offset_days' => null, 'end_offset_days' => null], 'Booting check', 'गाभा जाँच'),
        'info' => cropTimelineTemplate($context, ['schedule_type' => 'before_sowing', 'start_offset_days' => 0, 'end_offset_days' => 7,
            'activity_type' => 'information', 'allow_status_update' => false], 'Good sowing conditions', 'अनुकूल परिस्थितियाँ'),
    ];
}

function cropTimelineActivity(int $cycleId, CropActivityTemplate $template): FarmCropActivity
{
    return FarmCropActivity::query()->where('farm_crop_cycle_id', $cycleId)->where('crop_activity_template_id', $template->id)->firstOrFail();
}

test('generates the timeline once from the existing resolver and skips templates without timing', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    Log::spy();

    $first = $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk()
        ->assertJsonPath('data.generated', true)->assertJsonPath('data.skipped', 1)
        ->assertJsonPath('data.alert.show', true)->assertJsonPath('data.stats.activities', 4)
        ->assertJsonPath('data.stats.total_tasks', 3)->assertJsonPath('data.stats.growth_stages', 1);
    expect(array_column($first->json('data.sections'), 'type'))->toBe(['before_sowing', 'before_sowing', 'growth_stage', 'after_harvest']);

    $before = cropTimelineActivity($id, $templates['before']);
    expect([$before->start_date->toDateString(), $before->end_date->toDateString()])->toBe(['2026-10-22', '2026-10-30']);
    $stage = cropTimelineActivity($id, $templates['stage']);
    expect([$stage->start_date->toDateString(), $stage->end_date->toDateString()])->toBe(['2026-11-21', '2026-12-16']);
    $harvest = cropTimelineActivity($id, $templates['harvest']);
    expect([$harvest->start_date->toDateString(), $harvest->end_date->toDateString()])->toBe(['2027-02-09', '2027-02-14']);
    expect(FarmCropActivity::query()->where('crop_activity_template_id', $templates['untimed']->id)->exists())->toBeFalse();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $context['skipped_template_ids'] === [$templates['untimed']->id])->once();

    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk()->assertJsonPath('data.skipped', 1);
    $this->assertDatabaseCount('farm_crop_activities', 4);
});

test('returns display-ready sections, current stage, labels and placeholder record counts', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();

    $data = $this->getJson("/api/account/farm-crops/$id/timeline")->assertOk()->json('data');
    $growth = collect($data['sections'])->firstWhere('type', 'growth_stage');
    expect($data['current_stage_id'])->toBe($context['stage']->id);
    expect($growth['stage'])->toMatchArray(['id' => $context['stage']->id, 'sequence_label' => 'Growth stage 03', 'state' => 'current']);
    expect($growth['title'])->toBe('Tillering');
    expect($growth['other_title'])->toBe('कल्ले निकलना');
    expect($growth['marker'])->toBe(['icon' => 'DropletsIcon', 'icons' => [], 'more' => 0]);
    $activity = $growth['activities'][0];
    expect($activity)->toMatchArray([
        'title' => 'First Irrigation', 'other_title' => 'पहली सिंचाई', 'time_state' => 'due', 'when_tone' => 'due',
        'status' => 'pending', 'status_label' => 'Pending', 'records_total' => 0, 'record_types_remaining' => 1,
        'records' => ['notes' => 0, 'expenses' => 0, 'labour' => 0, 'photos' => 0],
    ]);
    expect($activity['when_label'])->toBe('20–45 days after sowing · 21 Nov – 16 Dec 2026 · 22 days left');
    expect($activity['category'])->toMatchArray(['name' => 'Irrigation', 'icon' => 'DropletsIcon', 'tone' => 'blue']);

    $before = collect($data['sections'])->where('type', 'before_sowing')->pluck('activities')->flatten(1)
        ->firstWhere('title', 'Field Preparation');
    expect($before['time_state'])->toBe('late');
    expect($before['when_label'])->toBe('10–2 days before sowing · 22 Oct – 30 Oct 2026 · ended 25 days ago');
    expect(array_column($before['smart_actions'], 'key'))->toBe(['weather_forecast', 'soil_test']);
    expect($before['smart_actions'][0])->toMatchArray(['label' => 'Weather Forecast', 'icon' => 'CloudSunIcon', 'used_at_label' => null]);
    expect($before['tools_remaining'])->toBe(2);
    $info = collect($data['sections'])->pluck('activities')->flatten(1)->firstWhere('title', 'Good sowing conditions');
    expect($info)->toMatchArray(['status' => null, 'status_label' => null, 'time_state' => 'passed', 'when_tone' => null]);
    expect($data['stats'])->toBe(['growth_stages' => 1, 'activities' => 4, 'due_now' => 1, 'late' => 1, 'completed' => 0, 'total_tasks' => 3]);
    expect($data['crop'])->toMatchArray(['sowing_date' => '2026-11-01', 'sowing_date_label' => '1 Nov 2026',
        'expected_harvest_label' => '9 Feb – 1 Mar 2027', 'days' => ['label' => 'Days after sowing', 'value' => 'Day 23']]);
});

test('uses the requested locale for titles and labels', function () {
    $context = cropTimelineContext();
    cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();

    $data = $this->getJson("/api/account/farm-crops/$id/timeline", ['X-Locale' => 'hi'])->assertOk()->json('data');
    $activity = collect($data['sections'])->firstWhere('type', 'growth_stage')['activities'][0];
    expect($activity['title'])->toBe('पहली सिंचाई');
    expect($activity['other_title'])->toBe('First Irrigation');
    expect($activity['status_label'])->toBe('बाकी');
    expect($activity['when_label'])->toStartWith('बुवाई के 20–45 दिन बाद');
});

test('generation requires an active crop and the read shows when a timeline is missing', function () {
    $context = cropTimelineContext();
    cropTimelineStandardTemplates($context);
    $draft = cropManagerReady($this, $context);

    $this->postJson('/api/account/farm-crops/'.$draft['id'].'/timeline/generate')->assertUnprocessable()->assertJsonValidationErrors('timeline');
    $this->getJson('/api/account/farm-crops/'.$draft['id'].'/timeline')->assertOk()
        ->assertJsonPath('data.generated', false)->assertJsonPath('data.can_generate', false)->assertJsonPath('data.sections', []);
    $this->assertDatabaseCount('farm_crop_activities', 0);
});

test('returns 404 for another farmers timeline activities and notes', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $activity = cropTimelineActivity($id, $templates['before']);
    $note = $this->postJson("/api/account/crop-activities/{$activity->id}/notes", ['note' => 'Ploughed', 'note_date' => '2026-11-20'])
        ->assertCreated()->json('data.activity.notes.0.id');

    app('auth')->forgetGuards();
    $this->withToken(User::factory()->create()->createToken('other', ['frontend'])->plainTextToken);
    $this->getJson("/api/account/farm-crops/$id/timeline")->assertNotFound();
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertNotFound();
    $this->postJson("/api/account/farm-crops/$id/timeline/alert-seen")->assertNotFound();
    $this->patchJson("/api/account/crop-activities/{$activity->id}/status", ['action' => 'complete'])->assertNotFound();
    $this->patchJson("/api/account/crop-activities/{$activity->id}/schedule", ['reset' => true])->assertNotFound();
    $this->postJson("/api/account/crop-activities/{$activity->id}/notes", ['note' => 'x', 'note_date' => '2026-11-20'])->assertNotFound();
    $this->deleteJson("/api/account/crop-activity-notes/$note")->assertNotFound();

    $this->assertDatabaseHas('farm_crop_activities', ['id' => $activity->id, 'status' => 'in_progress']);
    $this->assertDatabaseCount('farm_crop_activity_notes', 1);
});

test('the first note starts an activity, complete and reopen follow the status rules, and deleting notes never reverts', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $stage = cropTimelineActivity($id, $templates['stage']);
    $harvest = cropTimelineActivity($id, $templates['harvest']);

    $created = $this->postJson("/api/account/crop-activities/{$stage->id}/notes", ['note' => '  First watering done.  ', 'note_date' => '2026-11-23'])
        ->assertCreated()->assertJsonPath('data.activity.status', 'in_progress')->assertJsonPath('data.activity.records_total', 1)
        ->assertJsonPath('data.activity.record_types_remaining', 0)->assertJsonPath('data.activity.notes.0.text', 'First watering done.')
        ->assertJsonPath('data.activity.status_line', 'Status: In progress · since 24 Nov')->assertJsonPath('data.section.key', 'growth_stage:'.$context['stage']->id);
    $this->patchJson("/api/account/crop-activities/{$stage->id}/status", ['action' => 'complete'])->assertOk()
        ->assertJsonPath('data.activity.status', 'completed')->assertJsonPath('data.activity.time_state', 'done')
        ->assertJsonPath('data.activity.last_tile.key', 'completed_on')->assertJsonPath('data.stats.completed', 1)
        ->assertJsonPath('data.section.done_count', 1);
    $this->patchJson("/api/account/crop-activities/{$stage->id}/status", ['action' => 'reopen'])->assertOk()
        ->assertJsonPath('data.activity.status', 'in_progress')->assertJsonPath('data.activity.completed_at_label', null);
    $this->deleteJson('/api/account/crop-activity-notes/'.$created->json('data.activity.notes.0.id'))->assertOk()
        ->assertJsonPath('data.activity.status', 'in_progress')->assertJsonPath('data.activity.records_total', 0);

    // Completing early is allowed; reopening without records returns to pending.
    $this->patchJson("/api/account/crop-activities/{$harvest->id}/status", ['action' => 'complete'])->assertOk()->assertJsonPath('data.activity.status', 'completed');
    $this->patchJson("/api/account/crop-activities/{$harvest->id}/status", ['action' => 'reopen'])->assertOk()
        ->assertJsonPath('data.activity.status', 'pending');
    $this->assertDatabaseHas('farm_crop_activities', ['id' => $harvest->id, 'started_at' => null, 'completed_at' => null]);
    $this->patchJson("/api/account/crop-activities/{$harvest->id}/status", ['action' => 'archive'])->assertUnprocessable()->assertJsonValidationErrors('action');
});

test('information activities reject status changes and notes never give them a status', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $info = cropTimelineActivity($id, $templates['info']);

    $this->patchJson("/api/account/crop-activities/{$info->id}/status", ['action' => 'complete'])->assertUnprocessable()->assertJsonValidationErrors('action');
    $this->postJson("/api/account/crop-activities/{$info->id}/notes", ['note' => 'Read it', 'note_date' => '2026-11-24'])->assertCreated()
        ->assertJsonPath('data.activity.status', null);
    $this->assertDatabaseHas('farm_crop_activities', ['id' => $info->id, 'status' => 'pending', 'started_at' => null]);
});

test('validates notes as plain text up to 1000 characters dated today or earlier', function (array $payload, string $field) {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();

    $this->postJson('/api/account/crop-activities/'.cropTimelineActivity($id, $templates['stage'])->id.'/notes', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('farm_crop_activity_notes', 0);
})->with([
    'empty' => [['note' => '   ', 'note_date' => '2026-11-24'], 'note'],
    'too long' => [['note' => str_repeat('a', 1001), 'note_date' => '2026-11-24'], 'note'],
    'future date' => [['note' => 'Later', 'note_date' => '2026-11-25'], 'note_date'],
    'invalid date' => [['note' => 'Later', 'note_date' => '24-11-2026'], 'note_date'],
]);

test('reschedules only this activity, keeps planned dates for reset and enforces the flags', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $before = cropTimelineActivity($id, $templates['before']);
    $stage = cropTimelineActivity($id, $templates['stage']);

    $this->patchJson("/api/account/crop-activities/{$before->id}/schedule", ['start_date' => '2026-11-26', 'end_date' => '2026-11-25'])
        ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    $this->patchJson("/api/account/crop-activities/{$before->id}/schedule", ['start_date' => '2026-11-24', 'end_date' => '2026-11-28'])->assertOk()
        ->assertJsonPath('data.activity.is_rescheduled', true)->assertJsonPath('data.activity.time_state', 'due')
        ->assertJsonPath('data.activity.planned_start_date', '2026-10-22')->assertJsonPath('data.stats.late', 0);
    $moved = collect($this->getJson("/api/account/farm-crops/$id/timeline")->json('data.sections'))->pluck('activities')->flatten(1)
        ->firstWhere('id', $before->id);
    expect($moved['when_label'])->toStartWith('Rescheduled ·');
    $this->patchJson("/api/account/crop-activities/{$before->id}/schedule", ['reset' => true])->assertOk()
        ->assertJsonPath('data.activity.is_rescheduled', false)->assertJsonPath('data.activity.start_date', '2026-10-22');

    $this->patchJson("/api/account/crop-activities/{$stage->id}/schedule", ['start_date' => '2026-11-24', 'end_date' => '2026-11-28'])
        ->assertUnprocessable()->assertJsonValidationErrors('start_date');
    $this->patchJson("/api/account/crop-activities/{$before->id}/status", ['action' => 'complete'])->assertOk();
    $this->patchJson("/api/account/crop-activities/{$before->id}/schedule", ['reset' => true])->assertUnprocessable();
});

test('the ready alert shows once until it is marked seen', function () {
    $context = cropTimelineContext();
    cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);

    $this->postJson("/api/account/farm-crops/$id/timeline/alert-seen")->assertOk();
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk()->assertJsonPath('data.alert.show', true);
    $this->getJson("/api/account/farm-crops/$id/timeline")->assertJsonPath('data.alert.show', true);
    $this->postJson("/api/account/farm-crops/$id/timeline/alert-seen")->assertOk()->assertJsonPath('data.alert.show', false);
    $this->getJson("/api/account/farm-crops/$id/timeline")->assertJsonPath('data.alert.show', false);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk()->assertJsonPath('data.alert.show', false);
});

test('the crop list and detail report whether a timeline exists', function () {
    $context = cropTimelineContext();
    cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);

    $this->getJson('/api/account/farm-crops?status=active')->assertOk()->assertJsonPath('data.0.has_timeline', false);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $this->getJson('/api/account/farm-crops?status=active')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.has_timeline', true);
    $this->getJson("/api/account/farm-crops/$id")->assertOk()->assertJsonPath('data.timeline_generated', true)->assertJsonPath('data.has_timeline', true);
});

test('an active crop keeps its farm crop variety and season while the sowing date stays editable', function (string $field) {
    $context = cropTimelineContext();
    $id = cropTimelineActiveCrop($this, $context);
    $changes = [
        'crop_variety_id' => fn () => CropVariety::factory()->for($context['crop'])->withNames('Other', 'अन्य')->create(['is_active' => true])->id,
        'crop_id' => fn () => Crop::factory()->published('Mustard', 'सरसों')->create()->id,
        'season_id' => fn () => Season::factory()->withNames('Kharif', 'खरीफ')->create(['is_active' => true])->id,
        'farm_id' => fn () => Farm::factory()->for($context['user'])->inDistrict($context['district'])->create(['area' => '3.000', 'area_unit' => 'hectare'])->id,
    ];

    $this->putJson("/api/account/farm-crops/$id/step-1", [...cropManagerSelection($context), $field => $changes[$field](), 'revision' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('farm_crop_edit_drafts', 0);
})->with(['crop_variety_id', 'crop_id', 'season_id', 'farm_id']);

test('changing the sowing date of an active crop moves its timeline dates and keeps rescheduled activities and statuses', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $before = cropTimelineActivity($id, $templates['before']);
    $stage = cropTimelineActivity($id, $templates['stage']);
    $this->patchJson("/api/account/crop-activities/{$before->id}/schedule", ['start_date' => '2026-11-24', 'end_date' => '2026-11-28'])->assertOk();
    $this->patchJson("/api/account/crop-activities/{$stage->id}/status", ['action' => 'complete'])->assertOk();

    $this->putJson("/api/account/farm-crops/$id/step-1", [...cropManagerSelection($context), 'sowing_date' => '2026-11-08', 'revision' => 3])->assertOk();
    // Pending edits do not touch the live timeline.
    expect(cropTimelineActivity($id, $templates['stage'])->start_date->toDateString())->toBe('2026-11-21');
    $this->postJson("/api/account/farm-crops/$id/finalize", ['revision' => 4])->assertOk()->assertJsonPath('data.timeline_generated', true);

    $stage->refresh();
    expect([$stage->planned_start_date->toDateString(), $stage->start_date->toDateString(), $stage->end_date->toDateString(), $stage->status])
        ->toBe(['2026-11-28', '2026-11-28', '2026-12-23', 'completed']);
    $before->refresh();
    expect([$before->planned_start_date->toDateString(), $before->start_date->toDateString(), $before->end_date->toDateString()])
        ->toBe(['2026-10-29', '2026-11-24', '2026-11-28']);
    $this->assertDatabaseCount('farm_crop_activities', 4);
    $growth = collect($this->getJson("/api/account/farm-crops/$id/timeline")->json('data.sections'))->firstWhere('type', 'growth_stage');
    expect($growth['stage']['range_label'])->toBe('20–45 days after sowing · 28 Nov – 23 Dec 2026');
});

test('a cancelled crop can be deleted permanently with its timeline activities and notes', function () {
    $context = cropTimelineContext();
    $templates = cropTimelineStandardTemplates($context);
    $id = cropTimelineActiveCrop($this, $context);
    $this->postJson("/api/account/farm-crops/$id/timeline/generate")->assertOk();
    $activity = cropTimelineActivity($id, $templates['stage']);
    $this->postJson("/api/account/crop-activities/{$activity->id}/notes", ['note' => 'Wrong crop', 'note_date' => '2026-11-24'])->assertCreated();

    // The first delete cancels the active crop and keeps its history.
    $this->deleteJson("/api/account/farm-crops/$id")->assertOk();
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'status' => 'cancelled']);
    $this->assertDatabaseCount('farm_crop_activities', 4);

    $other = User::factory()->create()->createToken('other', ['frontend'])->plainTextToken;
    app('auth')->forgetGuards();
    $this->withToken($other)->deleteJson("/api/account/farm-crops/$id")->assertNotFound();
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id]);

    app('auth')->forgetGuards();
    $this->withToken($context['user']->createToken('owner', ['frontend'])->plainTextToken)
        ->deleteJson("/api/account/farm-crops/$id")->assertOk();
    $this->assertDatabaseMissing('farm_crop_cycles', ['id' => $id]);
    $this->assertDatabaseCount('farm_crop_activities', 0);
    $this->assertDatabaseCount('farm_crop_activity_notes', 0);
    $this->getJson("/api/account/farm-crops/$id/timeline")->assertNotFound();
});

test('completed crops stay in history and cannot be deleted', function () {
    $context = cropTimelineContext();
    $id = cropTimelineActiveCrop($this, $context);
    FarmCropCycle::query()->whereKey($id)->update(['status' => 'completed']);

    $this->deleteJson("/api/account/farm-crops/$id")->assertConflict();
    $this->assertDatabaseHas('farm_crop_cycles', ['id' => $id, 'status' => 'completed']);
});

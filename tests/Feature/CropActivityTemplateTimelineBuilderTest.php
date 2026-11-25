<?php

use App\Models\ActivityCategory;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(LazilyRefreshDatabase::class);

/** @return array{crop: Crop, stage: CropStage, variety: CropVariety, category: ActivityCategory, season: Season, district: Region} */
function builderContext(): array
{
    $crop = Crop::factory()->published('Wheat', 'गेहूँ')->create();
    $country = Region::factory()->withEnglishName('India')->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();

    return [
        'crop' => $crop,
        'stage' => CropStage::factory()->for($crop)->withNames('Tillering', 'कल्ले')->create(['is_active' => true]),
        'variety' => CropVariety::factory()->for($crop)->withNames('HD 2967', 'एचडी 2967')->create(['is_active' => true]),
        'category' => ActivityCategory::factory()->published()->create(),
        'season' => Season::factory()->withNames('Rabi', 'रबी')->create(['is_active' => true]),
        'district' => Region::factory()->under($state)->withEnglishName('Jaipur')->create(),
    ];
}

/** @param array<string, mixed> $context @param array<string, mixed> $overrides @return array<string, mixed> */
function builderPayload(array $context, array $overrides = []): array
{
    return array_replace([
        'crop_id' => $context['crop']->id,
        'category_id' => $context['category']->id,
        'crop_stage_id' => $context['stage']->id,
        'variety_scope' => 'all',
        'season_scope' => 'all',
        'region_scope' => 'all',
        'schedule_type' => 'after_sowing',
        'schedule_basis' => 'fixed_offsets',
        'start_offset_days' => 12,
        'end_offset_days' => 18,
        'translations' => [
            'en' => ['title' => 'Inspect tillers', 'instructions' => 'Illustrative test instructions.'],
            'hi' => ['title' => 'कल्लों की जाँच करें', 'instructions' => 'परीक्षण के उदाहरण निर्देश।'],
        ],
    ], $overrides);
}

/** @param array<string, mixed> $context @param array<string, mixed> $overrides */
function builderTemplate(array $context, array $overrides = []): CropActivityTemplate
{
    return CropActivityTemplate::factory()->withTitles()->create(array_replace([
        'crop_id' => $context['crop']->id,
        'category_id' => $context['category']->id,
        'crop_stage_id' => $context['stage']->id,
        'season_scope' => 'all',
        'start_offset_days' => 12,
        'end_offset_days' => 18,
    ], $overrides));
}

/** @param array<string, mixed> $overrides */
function builderTimeline(CropStage $stage, int $min, int $max, array $overrides = []): CropStageTimeline
{
    return CropStageTimeline::factory()->for($stage, 'cropStage')->create(array_replace([
        'days_after_sowing_min' => $min, 'days_after_sowing_max' => $max, 'is_active' => true,
    ], $overrides));
}

function builderToken(bool $reviewer = true): string
{
    $admin = ($reviewer ? AdminUser::factory()->superUser() : AdminUser::factory())->create();

    return $admin->createToken('test', ['admin'])->plainTextToken;
}

test('timeline builder endpoints require an admin bearer token', function (string $method, string $uri) {
    $this->json($method, '/api/admin/crop-activity-templates/'.$uri)
        ->assertUnauthorized()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with([
    'metadata' => ['GET', 'timeline-builder/metadata'], 'resolve' => ['POST', 'timeline-builder/resolve'],
    'bulk' => ['POST', 'bulk'], 'import' => ['POST', 'import'],
]);

test('crop management denial protects timeline builder endpoints', function (string $method, string $uri) {
    $token = builderToken();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($token)->json($method, '/api/admin/crop-activity-templates/'.$uri)->assertForbidden();
})->with([
    'metadata' => ['GET', 'timeline-builder/metadata'], 'resolve' => ['POST', 'timeline-builder/resolve'],
    'bulk' => ['POST', 'bulk'], 'import' => ['POST', 'import'],
]);

test('metadata exposes supported and required template locales', function () {
    $response = $this->withToken(builderToken())->getJson('/api/admin/crop-activity-templates/timeline-builder/metadata')
        ->assertOk()
        ->assertJsonPath('data.locales.default', 'en')
        ->assertJsonPath('data.locales.supported', [['code' => 'en', 'name' => 'English'], ['code' => 'hi', 'name' => 'हिन्दी']])
        ->assertJsonPath('data.locales.required', CropActivityTemplate::REQUIRED_LOCALES)
        ->assertJsonPath('data.schedule_bases', ['fixed_offsets', 'growth_stage', 'pending']);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('creating a template keeps the existing contract and adds builder fields', function () {
    $context = builderContext();

    $response = $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates', builderPayload($context, ['display_order' => 3]))
        ->assertCreated()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.schedule_type', 'after_sowing')
        ->assertJsonPath('data.display_order', 3)
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.scheduling_reconfirmation_required', false)
        ->assertJsonPath('data.timing.code', 'missing')
        ->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
});

test('repeating a creation request id returns the original template instead of a duplicate', function () {
    $context = builderContext();
    $token = builderToken();
    $payload = builderPayload($context, ['creation_request_id' => 'builder-req-1']);

    $first = $this->withToken($token)->postJson('/api/admin/crop-activity-templates', $payload)->assertCreated();
    $this->withToken($token)->postJson('/api/admin/crop-activity-templates', $payload)
        ->assertOk()->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('message', 'Crop activity template was already created for this request.');

    $this->assertDatabaseCount('crop_activity_templates', 1);
});

test('creation request ids cannot be supplied on update', function () {
    $context = builderContext();
    $template = builderTemplate($context);

    $this->withToken(builderToken())->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['creation_request_id' => 'late'])
        ->assertUnprocessable()->assertJsonValidationErrors('creation_request_id');
});

test('inactive drafts can be saved with pending scheduling but cannot be activated', function () {
    $context = builderContext();
    $token = builderToken();

    $id = $this->withToken($token)->postJson('/api/admin/crop-activity-templates', builderPayload($context, [
        'schedule_type' => null, 'schedule_basis' => 'pending', 'start_offset_days' => 5, 'end_offset_days' => 9,
    ]))->assertCreated()
        ->assertJsonPath('data.schedule_basis', 'pending')
        ->assertJsonPath('data.schedule_type', null)
        ->assertJsonPath('data.start_offset_days', null)
        ->assertJsonPath('data.timing.code', 'pending')
        ->assertJsonPath('data.readiness.configured', false)
        ->assertJsonPath('data.readiness.reasons.0.code', 'scheduling_pending')
        ->json('data.id');

    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('schedule_basis');
    expect(CropActivityTemplate::findOrFail($id)->is_active)->toBeFalse();
});

test('non-pending templates still require a schedule type', function () {
    $context = builderContext();

    $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates', builderPayload($context, ['schedule_type' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors('schedule_type');
    $this->assertDatabaseCount('crop_activity_templates', 0);
});

test('stale expected versions are rejected with 409 and leave the template unchanged', function () {
    $context = builderContext();
    $template = builderTemplate($context, ['priority' => 'low']);
    $token = builderToken();

    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['priority' => 'high', 'expected_version' => 1])
        ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.priority', 'high');
    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['priority' => 'medium', 'expected_version' => 1])
        ->assertConflict()->assertJsonPath('status', false)->assertJsonPath('data', null);
    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', ['is_active' => true, 'expected_version' => 1])
        ->assertConflict();

    expect($template->fresh())->priority->toBe('high')->version->toBe(2)->is_active->toBeFalse();
});

test('resolution returns non-dominated windows per context with provenance', function () {
    $context = builderContext();
    $generic = builderTimeline($context['stage'], 10, 20);
    $specific = builderTimeline($context['stage'], 15, 25, ['variety_scope' => 'specific']);
    $specific->varieties()->sync([$context['variety']->id]);
    builderTimeline($context['stage'], 1, 2, ['is_active' => false]);

    $response = $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/timeline-builder/resolve', [
        'crop_id' => $context['crop']->id, 'crop_stage_ids' => [$context['stage']->id],
        'variety_scope' => 'all', 'season_scope' => 'all', 'region_scope' => 'all',
    ])->assertOk();

    expect($response->json('data.stages.0'))->toMatchArray([
        'crop_stage_id' => $context['stage']->id, 'missing_count' => 0, 'conflict_count' => 0, 'total_contexts' => 2,
    ]);
    expect($response->json('data.stages.0.windows'))->toBe([
        ['min' => 10, 'max' => 20, 'stage_timeline_ids' => [$generic->id], 'context_count' => 1,
            'sample_contexts' => [['crop_variety_id' => null, 'season_id' => $context['season']->id, 'region_id' => $context['district']->id]]],
        ['min' => 15, 'max' => 25, 'stage_timeline_ids' => [$specific->id], 'context_count' => 1,
            'sample_contexts' => [['crop_variety_id' => $context['variety']->id, 'season_id' => $context['season']->id, 'region_id' => $context['district']->id]]],
    ]);
});

test('resolution reports conflicts and missing coverage', function () {
    $context = builderContext();
    $first = builderTimeline($context['stage'], 10, 20);
    $second = builderTimeline($context['stage'], 30, 40);
    $uncovered = CropStage::factory()->for($context['crop'])->withNames('Heading', 'बाली')->create(['is_active' => true, 'sequence' => 2]);

    $stages = $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/timeline-builder/resolve', [
        'crop_id' => $context['crop']->id, 'crop_stage_ids' => [$context['stage']->id, $uncovered->id],
        'variety_scope' => 'specific', 'crop_variety_ids' => [$context['variety']->id],
        'season_scope' => 'specific', 'season_ids' => [$context['season']->id],
        'region_scope' => 'specific', 'region_ids' => [$context['district']->id],
    ])->assertOk()->json('data.stages');

    expect($stages[0])->toMatchArray(['windows' => [], 'conflict_count' => 1, 'missing_count' => 0, 'total_contexts' => 1]);
    expect($stages[0]['conflicts'][0]['stage_timeline_ids'])->toBe([$first->id, $second->id]);
    expect($stages[1])->toMatchArray(['windows' => [], 'conflict_count' => 0, 'missing_count' => 1, 'total_contexts' => 1]);
});

test('resolution rejects stages and varieties from other crops', function () {
    $context = builderContext();
    $other = builderContext();

    $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/timeline-builder/resolve', [
        'crop_id' => $context['crop']->id, 'crop_stage_ids' => [$other['stage']->id],
        'variety_scope' => 'specific', 'crop_variety_ids' => [$other['variety']->id],
        'season_scope' => 'all', 'region_scope' => 'all',
    ])->assertUnprocessable()->assertJsonValidationErrors(['crop_stage_ids.0', 'crop_variety_ids.0']);
});

test('fixed offsets outside stage timing need a justification to be configured', function () {
    $context = builderContext();
    builderTimeline($context['stage'], 10, 20);
    $template = builderTemplate($context, ['start_offset_days' => 30, 'end_offset_days' => 40]);
    $token = builderToken();

    $reasons = $this->withToken($token)->getJson('/api/admin/crop-activity-templates/'.$template->id)
        ->assertOk()->assertJsonPath('data.timing.code', 'outside')->assertJsonPath('data.timing.timing_exception', true)
        ->assertJsonPath('data.timing.windows.0.min', 10)
        ->json('data.readiness.reasons');
    expect(array_column($reasons, 'code'))->toContain('timing_exception_unjustified');

    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['timing_exception_reason' => 'Local agronomist advice.'])
        ->assertOk()->assertJsonPath('data.timing_exception_reason', 'Local agronomist advice.')
        ->assertJsonPath('data.readiness.configured', true)->assertJsonPath('data.readiness.reasons', []);
});

test('partially overlapping offsets are reported as partial', function () {
    $context = builderContext();
    builderTimeline($context['stage'], 10, 20);
    $template = builderTemplate($context, ['start_offset_days' => 15, 'end_offset_days' => 25]);

    $this->withToken(builderToken())->getJson('/api/admin/crop-activity-templates/'.$template->id)
        ->assertOk()->assertJsonPath('data.timing.code', 'partial')->assertJsonPath('data.timing.offset_fit', 'partial');
});

test('stage timing changes require fixed offset templates to reconfirm scheduling', function () {
    $context = builderContext();
    $timeline = builderTimeline($context['stage'], 10, 20, ['is_active' => false]);
    $template = builderTemplate($context);
    $growth = builderTemplate($context, ['schedule_basis' => 'growth_stage', 'start_offset_days' => null, 'end_offset_days' => null]);
    $token = builderToken();

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])->assertOk();

    expect($template->fresh()->scheduling_reconfirmation_required)->toBeTrue()
        ->and($template->fresh()->version)->toBe(1)
        ->and($growth->fresh()->scheduling_reconfirmation_required)->toBeFalse();
    $this->withToken($token)->getJson('/api/admin/crop-activity-templates/'.$template->id)
        ->assertJsonPath('data.readiness.reasons.0.code', 'scheduling_reconfirmation_required');

    $this->withToken($token)->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['start_offset_days' => 12])
        ->assertOk()->assertJsonPath('data.scheduling_reconfirmation_required', false);
});

test('template listings expose every translated title and district selection', function () {
    $context = builderContext();
    $template = builderTemplate($context, ['region_scope' => 'specific']);
    $template->regions()->sync([$context['district']->id]);
    builderTemplate($context);

    $response = $this->withToken(builderToken())->getJson('/api/admin/crop-activity-templates?crop_id='.$context['crop']->id)->assertOk();
    $item = collect($response->json('data'))->firstWhere('id', $template->id);

    expect($item['translation_titles'])->toBe(['en' => 'Example activity', 'hi' => 'उदाहरण गतिविधि'])
        ->and($item['region_ids'])->toBe([$context['district']->id])
        ->and($item)->not->toHaveKeys(['regions', 'timing', 'readiness']);

    $this->withToken(builderToken())->getJson('/api/admin/crop-activity-templates?include_assessment=1')
        ->assertOk()->assertJsonPath('data.0.timing.code', 'missing')->assertJsonStructure(['data' => [['readiness' => ['configured', 'reasons']]]]);
});

test('bulk updates report per-record outcomes without affecting other records', function () {
    $context = builderContext();
    $low = builderTemplate($context, ['priority' => 'low']);
    $high = builderTemplate($context, ['priority' => 'high']);

    $response = $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/bulk', [
        'ids' => [$low->id, $high->id, 999999], 'operation' => 'priority', 'data' => ['priority' => 'high'],
    ])->assertOk();

    expect($response->json('data.results'))->toBe([
        ['id' => $low->id, 'status' => 'updated', 'reason' => null, 'message' => null],
        ['id' => $high->id, 'status' => 'skipped', 'reason' => 'no_changes', 'message' => 'The template already has these values.'],
        ['id' => 999999, 'status' => 'failed', 'reason' => 'not_found', 'message' => 'The crop activity template does not exist.'],
    ])->and($response->json('data.totals'))->toBe(['requested' => 3, 'updated' => 1, 'skipped' => 1, 'failed' => 1]);
    expect($low->fresh()->priority)->toBe('high');
});

test('bulk validation failures and protected deletions fail only their own records', function () {
    $context = builderContext();
    $draft = builderTemplate($context);
    $active = builderTemplate($context, ['is_active' => true]);
    $token = builderToken();

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/bulk', ['ids' => [$draft->id, $active->id], 'operation' => 'delete'])
        ->assertOk()->assertJsonPath('data.results.0.status', 'updated')
        ->assertJsonPath('data.results.1.status', 'failed')->assertJsonPath('data.results.1.reason', 'conflict');
    $this->assertModelMissing($draft);
    $this->assertModelExists($active);

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/bulk', ['ids' => [$active->id], 'operation' => 'clear_schedule'])
        ->assertOk()->assertJsonPath('data.results.0.reason', 'validation_failed')
        ->assertJsonPath('data.results.0.errors.schedule_basis.0', 'Configure scheduling before activating this template.');
    expect($active->fresh()->schedule_basis)->toBe('fixed_offsets');
});

test('bulk knowledge source and smart action modes merge with existing selections', function () {
    $context = builderContext();
    $template = builderTemplate($context, ['smart_actions' => ['soil_test']]);

    $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/bulk', [
        'ids' => [$template->id], 'operation' => 'smart_actions', 'data' => ['smart_actions' => ['weather_alerts'], 'mode' => 'add'],
    ])->assertOk()->assertJsonPath('data.results.0.status', 'updated');

    expect($template->fresh()->smart_actions)->toBe(['soil_test', 'weather_alerts']);
});

test('bulk requests validate operations, data fields, and activation permission', function () {
    $context = builderContext();
    $template = builderTemplate($context);

    $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/bulk', [
        'ids' => [$template->id], 'operation' => 'priority', 'data' => ['priority' => 'high', 'is_active' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors('data.is_active');
    $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/bulk', ['ids' => [], 'operation' => 'explode'])
        ->assertUnprocessable()->assertJsonValidationErrors(['ids', 'operation']);
});

test('bulk activation requires the reviewer permission', function () {
    $template = builderTemplate(builderContext());

    $this->withToken(builderToken(reviewer: false))->postJson('/api/admin/crop-activity-templates/bulk', ['ids' => [$template->id], 'operation' => 'activate'])
        ->assertForbidden();
    expect($template->fresh()->is_active)->toBeFalse();
});

test('import previews report each row without writing', function () {
    $context = builderContext();

    $response = $this->withToken(builderToken())->postJson('/api/admin/crop-activity-templates/import', [
        'mode' => 'preview',
        'templates' => [builderPayload($context), builderPayload($context, ['translations' => ['en' => ['title' => 'Only English']]])],
    ])->assertOk()->assertJsonPath('data.totals', ['requested' => 2, 'valid' => 1, 'invalid' => 1])
        ->assertJsonPath('data.results.0.status', 'valid')->assertJsonPath('data.results.1.status', 'invalid');

    expect($response->json('data.results.1.errors'))->toHaveKey('translations.hi');
    $this->assertDatabaseCount('crop_activity_templates', 0);
});

test('import commits are all or nothing and create inactive drafts', function () {
    $context = builderContext();
    $token = builderToken();

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/import', [
        'mode' => 'commit', 'templates' => [builderPayload($context), builderPayload($context, ['is_active' => true])],
    ])->assertUnprocessable()->assertJsonValidationErrors('templates.1.is_active');
    $this->assertDatabaseCount('crop_activity_templates', 0);

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/import', [
        'mode' => 'commit', 'templates' => [builderPayload($context, ['creation_request_id' => 'import-1']), builderPayload($context)],
    ])->assertCreated()->assertJsonPath('data.totals', ['requested' => 2, 'created' => 2, 'existing' => 0]);
    $this->assertDatabaseCount('crop_activity_templates', 2);
    expect(CropActivityTemplate::query()->where('is_active', true)->exists())->toBeFalse();

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/import', [
        'mode' => 'commit', 'templates' => [builderPayload($context, ['creation_request_id' => 'import-1'])],
    ])->assertOk()->assertJsonPath('data.results.0.status', 'existing');
    $this->assertDatabaseCount('crop_activity_templates', 2);
});

test('growth stage templates activate and report overlaps with active templates of the same stage', function () {
    $context = builderContext();
    $token = builderToken();
    $stageBased = ['schedule_basis' => 'growth_stage', 'start_offset_days' => null, 'end_offset_days' => null];
    $sameStageFixed = builderTemplate($context, ['is_active' => true, 'start_offset_days' => 40, 'end_offset_days' => 50]);
    $sameStageGrowth = builderTemplate($context, ['is_active' => true] + $stageBased);
    $otherStage = CropStage::factory()->for($context['crop'])->withNames()->create(['is_active' => true, 'sequence' => 2]);
    builderTemplate($context, ['is_active' => true, 'crop_stage_id' => $otherStage->id] + $stageBased);
    builderTemplate($context, ['is_active' => true, 'crop_stage_id' => null]);
    $template = builderTemplate($context, $stageBased);
    $path = '/api/admin/crop-activity-templates/'.$template->id;

    // Previously a 500: the overlap query compared offsets with NULL.
    $this->withToken($token)->patchJson($path.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.publication_warnings.0.code', 'potential_overlap')
        ->assertJsonPath('data.publication_warnings.0.template_ids', [$sameStageFixed->id, $sameStageGrowth->id]);
    $this->withToken($token)->patchJson($path, ['priority' => 'high'])->assertOk()
        ->assertJsonPath('data.publication_warnings.0.template_ids', [$sameStageFixed->id, $sameStageGrowth->id]);
    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => true, 'priority' => 'high']);

    // A fixed-offset template reports growth-stage templates of its own stage.
    $fixed = builderTemplate($context, ['start_offset_days' => 0, 'end_offset_days' => 5]);
    expect($fixed->potentialOverlapIds([]))->toBe([$sameStageGrowth->id, $template->id]);

    // Stage-less fixed-offset templates are not compared with growth-stage ones.
    $stageless = builderTemplate($context, ['crop_stage_id' => null, 'start_offset_days' => 100, 'end_offset_days' => 110]);
    expect($stageless->potentialOverlapIds([]))->toBe([]);
});

test('final smart action keys are advertised and saved while legacy proposals are rejected', function () {
    $context = builderContext();
    $token = builderToken();
    $template = builderTemplate($context, ['smart_actions' => ['crop_details']]);
    $path = '/api/admin/crop-activity-templates/'.$template->id;
    $final = ['field_layout_spacing', 'sowing_planting_method', 'choose_seed'];

    $actions = $this->withToken($token)->getJson('/api/admin/crop-activity-templates/timeline-builder/metadata')
        ->assertOk()->json('data.smart_actions');
    expect($actions)->toHaveCount(39)->toContain(...$final)->not->toContain('choose_crop_variety_seed', 'planting_method');

    $this->withToken($token)->patchJson($path, ['smart_actions' => ['crop_details', ...$final]])
        ->assertOk()->assertJsonPath('data.smart_actions', ['crop_details', ...$final]);

    $this->withToken($token)->postJson('/api/admin/crop-activity-templates/bulk', [
        'ids' => [$template->id], 'operation' => 'smart_actions', 'data' => ['smart_actions' => ['seed_record'], 'mode' => 'add'],
    ])->assertOk()->assertJsonPath('data.results.0.status', 'updated');
    expect($template->fresh()->smart_actions)->toBe(['crop_details', ...$final, 'seed_record']);

    foreach (['choose_crop_variety_seed', 'planting_method'] as $legacy) {
        $this->withToken($token)->patchJson($path, ['smart_actions' => [$legacy]])
            ->assertUnprocessable()->assertJsonValidationErrors('smart_actions.0');
    }
    expect($template->fresh()->smart_actions)->toBe(['crop_details', ...$final, 'seed_record']);
});

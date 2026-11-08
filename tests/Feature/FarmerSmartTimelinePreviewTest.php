<?php

use App\Models\ActivityCategory;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
use App\Models\Region;
use App\Models\Season;
use App\Models\User;
use App\Services\FarmerSmartTimelineGenerator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function smartPreviewContext(): array
{
    $crop = Crop::factory()->published('Wheat', 'गेहूँ')->create();
    $state = Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return [
        'crop' => $crop,
        'variety' => CropVariety::factory()->for($crop)->withNames('Test variety', 'परीक्षण किस्म')->create(['is_active' => true]),
        'season' => Season::factory()->withNames('Rabi', 'रबी')->create(['is_active' => true]),
        'district' => Region::factory()->under($state)->withEnglishName('Jaipur')->create(),
        'stage' => CropStage::factory()->for($crop)->withNames('Seedling', 'अंकुर')->create(['is_active' => true]),
        'category' => ActivityCategory::factory()->withNames('Monitoring', 'निगरानी')->create(['is_active' => true]),
        'source' => KnowledgeSource::factory()->active()->create(['title' => 'Illustrative source']),
    ];
}

/** @param array<string, mixed> $context @return array<string, mixed> */
function smartPreviewInput(array $context, array $overrides = []): array
{
    return array_replace([
        'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id,
        'season_id' => $context['season']->id, 'sowing_date' => '2026-11-01', 'district_id' => $context['district']->id,
    ], $overrides);
}

/** @param array<string, mixed> $context */
function smartPreviewStage(array $context, array $overrides = []): CropStageTimeline
{
    return CropStageTimeline::factory()->for($context['stage'], 'cropStage')->for($context['source'], 'source')
        ->create(array_replace(['season_id' => $context['season']->id, 'is_active' => true], $overrides));
}

/** @param array<string, mixed> $context */
function smartPreviewActivity(array $context, array $overrides = []): CropActivityTemplate
{
    return CropActivityTemplate::factory()->for($context['crop'], 'crop')->for($context['category'], 'category')
        ->for($context['season'], 'season')->for($context['source'], 'source')->withTitles('Inspect field', 'खेत जाँचें')
        ->create(array_replace(['is_active' => true], $overrides));
}

test('smart timeline preview returns 401 without an admin token or with a farmer identity', function (bool $farmer) {
    if ($farmer) {
        $user = User::factory()->create();
        $this->withToken($user->createToken('test', ['*'])->plainTextToken);
    }

    $this->postJson('/api/admin/farmer-smart-timeline/preview', [])->assertUnauthorized()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with(['anonymous' => false, 'farmer' => true]);

test('smart timeline preview returns 403 for inactive admins and wrong token abilities', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', [])->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('smart timeline preview enforces the crop management gate before validation', function () {
    $admin = AdminUser::factory()->superUser()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', [])->assertForbidden();
});

test('smart timeline preview requires the four mandatory inputs with the validation envelope', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['crop_id', 'season_id', 'sowing_date', 'district_id'])
        ->assertJsonPath('errors.crop_id.0', 'The crop id field is required.');

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
});

test('smart timeline preview rejects invalid inputs with 422', function (string $field, mixed $value) {
    $context = smartPreviewContext();
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown crop' => ['crop_id', 999999], 'negative crop' => ['crop_id', -1], 'fractional crop' => ['crop_id', 1.5],
    'unknown variety' => ['crop_variety_id', 999999], 'variety array' => ['crop_variety_id', []],
    'unknown season' => ['season_id', 999999], 'unknown district' => ['district_id', 999999],
    'non leap day' => ['sowing_date', '2025-02-29'], 'invalid month day' => ['sowing_date', '2026-11-31'],
    'timestamp' => ['sowing_date', '2026-11-01T00:00:00Z'], 'non padded date' => ['sowing_date', '2026-1-1'],
    'zero year' => ['sowing_date', '0000-01-01'], 'date array' => ['sowing_date', []],
    'farmer' => ['farmer_id', 1], 'farm' => ['farm_id', 1], 'cycle' => ['crop_cycle_id', 1],
    'persist flag' => ['persist', true], 'request locale' => ['locale', 'hi'],
]);

test('smart timeline preview rejects inactive selections with 422', function (string $key, string $field) {
    $context = smartPreviewContext();
    $context[$key]->forceFill(['is_active' => false])->save();
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['crop' => ['crop', 'crop_id'], 'variety' => ['variety', 'crop_variety_id'], 'season' => ['season', 'season_id'], 'district' => ['district', 'district_id']]);

test('smart timeline preview rejects a variety belonging to another crop', function () {
    $context = smartPreviewContext();
    $variety = CropVariety::factory()->withNames()->create(['is_active' => true]);
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context, ['crop_variety_id' => $variety->id]))
        ->assertUnprocessable()->assertJsonPath('errors.crop_variety_id.0', 'The selected variety must belong to the selected crop.');
});

test('smart timeline preview accepts only district region IDs', function (string $type) {
    $context = smartPreviewContext();
    $region = Region::factory()->create(['region_type' => $type]);
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context, ['district_id' => $region->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('district_id');
})->with(['country', 'state', 'subdistrict', 'village']);

test('smart timeline preview returns an empty successful resource when configuration is missing', function () {
    $context = smartPreviewContext();
    $admin = AdminUser::factory()->create();
    $input = smartPreviewInput($context);
    unset($input['crop_variety_id']);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', $input)->assertOk()
        ->assertJsonPath('data.preview_only', true)->assertJsonPath('data.input.crop_variety_id', null)
        ->assertJsonPath('data.growth_stages', [])->assertJsonPath('data.activities', [])
        ->assertJsonPath('data.summary', ['total_growth_stages' => 0, 'total_activities' => 0, 'first_date' => null, 'last_date' => null])
        ->assertJsonPath('pagination', null)->assertJsonPath('status', true);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_column($response->json('data.warnings'), 'code'))->toContain('STAGE_TIMELINE_NOT_FOUND', 'ACTIVITY_TEMPLATES_NOT_FOUND', 'CONTENT_APPROVAL_LIMITATION');
    $missingStage = collect($response->json('data.warnings'))->firstWhere('code', 'STAGE_TIMELINE_NOT_FOUND');
    expect($missingStage['context'])->toBe(['crop_stage_id' => $context['stage']->id, 'crop_stage_name' => 'Seedling']);
    foreach ($response->json('data.warnings') as $warning) {
        expect(array_keys($warning))->toBe(['code', 'message', 'context']);
    }
});

test('smart timeline preview requires the crop publication prerequisites', function () {
    $context = smartPreviewContext();
    $context['crop']->category->forceFill(['is_active' => false])->save();
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');
});

test('stage specificity selects the highest eligible combination', function (array $ranks, int $expectedRank) {
    $context = smartPreviewContext();
    $ids = [];
    foreach ($ranks as $rank) {
        $timeline = smartPreviewStage($context, [
            'crop_variety_id' => $rank >= 2 ? $context['variety']->id : null,
            'region_scope' => $rank % 2 === 1 ? 'specific' : 'all',
            'days_after_sowing_min' => $rank * 10, 'days_after_sowing_max' => $rank * 10 + 5,
        ]);
        if ($rank % 2 === 1) {
            $timeline->regions()->attach($context['district']);
        }
        $ids[$rank] = $timeline->id;
    }

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['growth_stages'], 'stage_timeline_id'))->toBe([$ids[$expectedRank]]);
})->with(['all combinations' => [[0, 1, 2, 3], 3], 'exact variety wins over generic district' => [[0, 1, 2], 2], 'generic district' => [[0, 1], 1], 'generic all' => [[0], 0]]);

test('matching excludes unrelated crop season variety region and inactive records', function (string $kind, bool $activity) {
    $context = smartPreviewContext();
    $attributes = match ($kind) {
        'other crop' => $activity ? ['crop_id' => Crop::factory()->published()->create()->id]
            : ['crop_stage_id' => CropStage::factory()->withNames()->create(['is_active' => true])->id],
        'other season' => ['season_id' => Season::factory()->withNames()->create(['is_active' => true])->id],
        'other variety' => ['crop_variety_id' => CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true])->id],
        'inactive' => ['is_active' => false],
        'nonmatching district' => ['region_scope' => 'specific'],
        'unknown region scope' => ['region_scope' => 'invalid'],
    };
    $record = $activity ? smartPreviewActivity($context, $attributes) : smartPreviewStage($context, $attributes);
    if ($kind === 'nonmatching district') {
        $other = Region::factory()->under($context['district']->parent)->withEnglishName('Ajmer')->create();
        $record->regions()->attach($other);
    }

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result[$activity ? 'activities' : 'growth_stages'])->toBe([]);
})->with(['other crop', 'other season', 'other variety', 'inactive', 'nonmatching district', 'unknown region scope'])->with(['stage' => false, 'activity' => true]);

test('null season stage timelines never match an exact selected season', function () {
    $context = smartPreviewContext();
    smartPreviewStage($context, ['season_id' => null]);

    expect(app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context))['growth_stages'])->toBe([]);
});

test('no selected variety matches generic stages and activities only', function () {
    $context = smartPreviewContext();
    $generic = smartPreviewStage($context);
    smartPreviewStage($context, ['crop_variety_id' => $context['variety']->id, 'days_after_sowing_max' => 99]);
    $activity = smartPreviewActivity($context);
    smartPreviewActivity($context, ['crop_variety_id' => $context['variety']->id]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context, ['crop_variety_id' => null]));

    expect(array_column($result['growth_stages'], 'stage_timeline_id'))->toBe([$generic->id]);
    expect(array_column($result['activities'], 'template_id'))->toBe([$activity->id]);
});

test('disagreeing highest ranked stages are omitted while unaffected stages and activities remain', function () {
    $context = smartPreviewContext();
    $first = smartPreviewStage($context, ['crop_variety_id' => $context['variety']->id]);
    $second = smartPreviewStage($context, ['crop_variety_id' => $context['variety']->id, 'days_after_sowing_max' => 20]);
    smartPreviewStage($context);
    $otherStage = CropStage::factory()->for($context['crop'])->withNames()->create(['sequence' => 2, 'is_active' => true]);
    $other = smartPreviewStage(array_replace($context, ['stage' => $otherStage]));
    $activity = smartPreviewActivity($context, ['crop_stage_id' => $context['stage']->id, 'start_offset_days' => 5, 'end_offset_days' => 7]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['growth_stages'], 'stage_timeline_id'))->toBe([$other->id]);
    expect(array_column($result['activities'], 'template_id'))->toBe([$activity->id]);
    expect($result['activities'][0]['start_date'])->toBe('2026-11-06');
    $warning = collect($result['warnings'])->firstWhere('code', 'STAGE_TIMELINE_CONFLICT');
    expect($warning['context']->timeline_ids)->toBe([$first->id, $second->id]);
    expect($warning['context']->crop_stage_name)->toBe('Seedling');
    expect($warning['context']->timeline_names)->toBe(['Seedling', 'Seedling']);
});

test('identical equally ranked stage windows retain every contributing citation ID', function () {
    $context = smartPreviewContext();
    $first = smartPreviewStage($context);
    $source = KnowledgeSource::factory()->active()->create();
    $second = smartPreviewStage($context, ['source_id' => $source->id]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['growth_stages'])->toHaveCount(1);
    expect($result['growth_stages'][0]['stage_timeline_ids'])->toBe([$first->id, $second->id]);
    expect($result['growth_stages'][0]['source_ids'])->toBe([$context['source']->id, $source->id]);
    expect(array_column($result['warnings'], 'code'))->toContain('STAGE_TIMELINE_DUPLICATE');
    $warning = collect($result['warnings'])->firstWhere('code', 'STAGE_TIMELINE_DUPLICATE');
    expect($warning['context']->timeline_names)->toBe(['Seedling', 'Seedling']);
});

test('stage order follows sequence while activity dates always use sowing day zero', function () {
    $context = smartPreviewContext();
    $context['stage']->forceFill(['sequence' => 2])->save();
    $earlier = CropStage::factory()->for($context['crop'])->withNames()->create(['sequence' => 1, 'is_active' => true]);
    smartPreviewStage($context, ['days_after_sowing_min' => 0, 'days_after_sowing_max' => 5]);
    smartPreviewStage(array_replace($context, ['stage' => $earlier]), ['days_after_sowing_min' => 30, 'days_after_sowing_max' => 40]);
    smartPreviewActivity($context, ['crop_stage_id' => $earlier->id, 'start_offset_days' => 10, 'end_offset_days' => 15]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['growth_stages'], 'crop_stage_id'))->toBe([$earlier->id, $context['stage']->id]);
    expect($result['activities'][0]['start_date'])->toBe('2026-11-11');
    expect($result['activities'][0]['end_date'])->toBe('2026-11-16');
    expect($result['summary'])->toBe(['total_growth_stages' => 2, 'total_activities' => 1, 'first_date' => '2026-11-01', 'last_date' => '2026-12-11']);
});

test('distinct generic specific and overlapping activities are all retained once', function () {
    $context = smartPreviewContext();
    $generic = smartPreviewActivity($context);
    $specific = smartPreviewActivity($context, ['crop_variety_id' => $context['variety']->id, 'region_scope' => 'specific']);
    $specific->regions()->attach([$context['district']->id, Region::factory()->under($context['district']->parent)->create()->id]);
    $later = smartPreviewActivity($context, ['start_offset_days' => 10, 'end_offset_days' => 12]);
    $specific->translations()->where('locale', 'en')->update(['title' => 'District inspection']);
    $later->translations()->where('locale', 'en')->update(['title' => 'Follow-up inspection']);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['activities'], 'template_id'))->toBe([$generic->id, $specific->id, $later->id]);
    expect(array_column($result['warnings'], 'code'))->toContain('POTENTIAL_ACTIVITY_OVERLAP');
    expect(array_column($result['activities'], 'status'))->toBe(['planned', 'planned', 'planned']);
    expect($result['activities'][0])->not->toHaveKeys(['id', 'uuid', 'farmer_activity_id']);
    $warning = collect($result['warnings'])->firstWhere('code', 'POTENTIAL_ACTIVITY_OVERLAP');
    expect($warning['context']->category_name)->toBe('Monitoring');
    expect($warning['context']->template_ids)->toBe([$generic->id, $specific->id, $later->id]);
    expect($warning['context']->template_titles)->toBe(['Inspect field', 'District inspection', 'Follow-up inspection']);
});

test('unrelated activity categories and stages do not produce overlap warnings', function () {
    $context = smartPreviewContext();
    smartPreviewActivity($context, ['crop_stage_id' => $context['stage']->id]);
    $otherStage = CropStage::factory()->for($context['crop'])->withNames()->create(['sequence' => 2, 'is_active' => true]);
    smartPreviewActivity($context, ['crop_stage_id' => $otherStage->id]);
    smartPreviewActivity($context, ['category_id' => ActivityCategory::factory()->published()->create()->id]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['activities'])->toHaveCount(3);
    expect(array_column($result['warnings'], 'code'))->not->toContain('POTENTIAL_ACTIVITY_OVERLAP');
});

test('activities with a valid stage remain when no stage timing exists', function () {
    $context = smartPreviewContext();
    $template = smartPreviewActivity($context, ['crop_stage_id' => $context['stage']->id]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['growth_stages'])->toBe([]);
    expect(array_column($result['activities'], 'template_id'))->toBe([$template->id]);
    expect($result['activities'][0]['start_date'])->toBe('2026-11-01');
});

test('ineligible stage masters suppress their estimates and associated activities', function (string $kind) {
    $context = smartPreviewContext();
    smartPreviewStage($context);
    smartPreviewActivity($context, ['crop_stage_id' => $context['stage']->id]);
    match ($kind) {
        'inactive' => $context['stage']->forceFill(['is_active' => false])->save(),
        'name missing' => $context['stage']->translations()->where('locale', 'hi')->delete(),
        'invalid code' => $context['stage']->forceFill(['code' => 'Invalid code'])->save(),
        'long code' => $context['stage']->forceFill(['code' => str_repeat('a', 51)])->save(),
        'sequence zero' => $context['stage']->forceFill(['sequence' => 0])->save(),
    };

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['growth_stages'])->toBe([]);
    expect($result['activities'])->toBe([]);
    $warnings = collect($result['warnings']);
    expect($warnings->firstWhere('code', 'STAGE_TIMELINE_INELIGIBLE')['context']->stage_timeline_name)->toBe('Seedling');
    expect($warnings->firstWhere('code', 'GROWTH_STAGES_NOT_FOUND')['context']->crop_name)->toBe('Wheat');
})->with(['inactive', 'name missing', 'invalid code', 'long code', 'sequence zero']);

test('activities exclude ineligible categories and incomplete published content', function (string $kind) {
    $context = smartPreviewContext();
    $template = smartPreviewActivity($context);
    match ($kind) {
        'inactive category' => $context['category']->forceFill(['is_active' => false])->save(),
        'unreleased doctor' => $context['category']->forceFill(['slug' => 'ask-ai-doctor'])->save(),
        'unreleased expert' => $context['category']->forceFill(['slug' => 'ask-agricultural-expert'])->save(),
        'category name missing' => $context['category']->translations()->where('locale', 'hi')->delete(),
        'title missing' => $template->translations()->where('locale', 'hi')->delete(),
        'instructions blank' => $template->translations()->where('locale', 'hi')->update(['instructions' => '  ']),
        'priority invalid' => $template->forceFill(['priority' => 'urgent'])->save(),
        'wrong crop stage' => $template->forceFill(['crop_stage_id' => CropStage::factory()->withNames()->create(['is_active' => true])->id])->save(),
    };

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['activities'])->toBe([]);
    expect(array_column($result['warnings'], 'code'))->toContain('ACTIVITY_TEMPLATE_INELIGIBLE');
    $warning = collect($result['warnings'])->firstWhere('code', 'ACTIVITY_TEMPLATE_INELIGIBLE');
    expect($warning['context']->template_title)->toBe('Inspect field');
})->with(['inactive category', 'unreleased doctor', 'unreleased expert', 'category name missing', 'title missing', 'instructions blank', 'priority invalid', 'wrong crop stage']);

test('unsupported scheduling types are diagnosed without generating dates', function () {
    $context = smartPreviewContext();
    smartPreviewActivity($context, ['schedule_type' => 'stage_relative']);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['activities'])->toBe([]);
    expect(array_column($result['warnings'], 'code'))->toContain('UNSUPPORTED_SCHEDULE_TYPE');
    $warning = collect($result['warnings'])->firstWhere('code', 'UNSUPPORTED_SCHEDULE_TYPE');
    expect($warning['context']->template_title)->toBe('Inspect field');
});

test('invalid legacy offsets are diagnosed safely', function (mixed $start, mixed $end, bool $activity) {
    $context = smartPreviewContext();
    if ($activity) {
        $record = smartPreviewActivity($context);
        DB::table('crop_activity_templates')->where('id', $record->id)->update(['start_offset_days' => $start, 'end_offset_days' => $end]);
    } else {
        $record = smartPreviewStage($context);
        DB::table('crop_stage_timelines')->where('id', $record->id)->update(['days_after_sowing_min' => $start, 'days_after_sowing_max' => $end]);
    }

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result[$activity ? 'activities' : 'growth_stages'])->toBe([]);
    expect(array_column($result['warnings'], 'code'))->toContain($activity ? 'INVALID_ACTIVITY_DAY_RANGE' : 'INVALID_STAGE_DAY_RANGE');
    $warning = collect($result['warnings'])->firstWhere('code', $activity ? 'INVALID_ACTIVITY_DAY_RANGE' : 'INVALID_STAGE_DAY_RANGE');
    expect($warning['context']->{$activity ? 'template_title' : 'stage_timeline_name'})->toBe($activity ? 'Inspect field' : 'Seedling');
})->with(['negative' => [-1, 10], 'reversed' => [11, 10], 'fractional' => [0.5, 10], 'text' => ['invalid', 10], 'out of schema range' => [0, 4294967296]])->with(['stage' => false, 'activity' => true]);

test('calendar arithmetic preserves boundaries and sowing date across timezones', function (string $date, int $start, int $end, string $expectedStart, string $expectedEnd) {
    $context = smartPreviewContext();
    smartPreviewStage($context, ['days_after_sowing_min' => $start, 'days_after_sowing_max' => $end]);
    smartPreviewActivity($context, ['start_offset_days' => $start, 'end_offset_days' => $end]);
    $admin = AdminUser::factory()->create();
    $timezone = date_default_timezone_get();
    config()->set('app.timezone', 'Pacific/Kiritimati');
    date_default_timezone_set('America/Los_Angeles');
    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context, ['sowing_date' => $date]))
            ->assertOk()->assertJsonPath('data.input.sowing_date', $date)
            ->assertJsonPath('data.activities.0.start_date', $expectedStart)->assertJsonPath('data.activities.0.end_date', $expectedEnd)
            ->assertJsonPath('data.growth_stages.0.start_date', $expectedStart)->assertJsonPath('data.growth_stages.0.end_date', $expectedEnd);
    } finally {
        date_default_timezone_set($timezone);
    }
})->with([
    'day zero' => ['2026-11-01', 0, 0, '2026-11-01', '2026-11-01'],
    'day ten' => ['2026-11-01', 10, 15, '2026-11-11', '2026-11-16'],
    'month' => ['2026-01-31', 1, 2, '2026-02-01', '2026-02-02'],
    'year' => ['2026-12-31', 1, 2, '2027-01-01', '2027-01-02'],
    'leap year' => ['2028-02-28', 1, 2, '2028-02-29', '2028-03-01'],
    'leap day input' => ['2028-02-29', 0, 1, '2028-02-29', '2028-03-01'],
    'historical' => ['2000-01-01', 0, 1, '2000-01-01', '2000-01-02'],
    'future' => ['2040-01-01', 0, 1, '2040-01-01', '2040-01-02'],
    'last supported date' => ['9999-12-31', 0, 0, '9999-12-31', '9999-12-31'],
]);

test('offsets outside the four digit calendar produce diagnostics instead of malformed dates', function () {
    $context = smartPreviewContext();
    smartPreviewStage($context, ['days_after_sowing_max' => 4294967295]);
    smartPreviewActivity($context, ['end_offset_days' => 2147483647]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['activities'])->toBe([]);
    expect($result['growth_stages'])->toBe([]);
    expect(collect($result['warnings'])->where('code', 'DATE_RANGE_UNSUPPORTED'))->toHaveCount(2);
    $warnings = collect($result['warnings'])->where('code', 'DATE_RANGE_UNSUPPORTED')->values();
    expect($warnings[0]['context']->crop_stage_name)->toBe('Seedling');
    expect($warnings[0]['context']->timeline_names)->toBe(['Seedling']);
    expect($warnings[1]['context']->template_title)->toBe('Inspect field');
});

test('activities are ordered by date then end date then template ID', function () {
    $context = smartPreviewContext();
    $late = smartPreviewActivity($context, ['start_offset_days' => 20, 'end_offset_days' => 25]);
    $long = smartPreviewActivity($context, ['start_offset_days' => 0, 'end_offset_days' => 15]);
    $early = smartPreviewActivity($context, ['start_offset_days' => 0, 'end_offset_days' => 10]);
    $tie = smartPreviewActivity($context, ['start_offset_days' => 0, 'end_offset_days' => 10]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['activities'], 'template_id'))->toBe([$early->id, $tie->id, $long->id, $late->id]);
});

test('source publication failures exclude both stages and activities', function (string $kind) {
    $context = smartPreviewContext();
    smartPreviewStage($context);
    smartPreviewActivity($context);
    $context['source']->forceFill(match ($kind) {
        'inactive' => ['is_active' => false], 'pending' => ['verification_status' => 'pending'],
        'rejected' => ['verification_status' => 'rejected'], 'missing reference' => ['source_url' => null, 'reference_identifier' => null],
    })->save();

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect($result['growth_stages'])->toBe([]);
    expect($result['activities'])->toBe([]);
    expect(array_column($result['warnings'], 'code'))->toContain('STAGE_TIMELINE_INELIGIBLE', 'ACTIVITY_TEMPLATE_INELIGIBLE');
})->with(['inactive', 'pending', 'rejected', 'missing reference']);

test('ineligible higher ranked sources do not hide a reliable generic stage estimate', function () {
    $context = smartPreviewContext();
    $generic = smartPreviewStage($context);
    smartPreviewStage($context, ['crop_variety_id' => $context['variety']->id, 'source_id' => KnowledgeSource::factory()->create()->id]);

    $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

    expect(array_column($result['growth_stages'], 'stage_timeline_id'))->toBe([$generic->id]);
});

test('attachment backed citations are checked without exposing private metadata', function (bool $exists) {
    Storage::fake(config('filesystems.knowledge_sources_disk'));
    $context = smartPreviewContext();
    $context['source']->forceFill(['source_url' => null, 'reference_identifier' => null, 'review_notes' => 'Private review note'])->save();
    $attachment = KnowledgeSourceAttachment::factory()->for($context['source'])->create();
    if ($exists) {
        Storage::disk(config('filesystems.knowledge_sources_disk'))->put($attachment->file_path, 'example');
    }
    smartPreviewStage($context);
    smartPreviewActivity($context);
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()
        ->assertJsonCount($exists ? 1 : 0, 'data.activities')->assertJsonCount($exists ? 1 : 0, 'data.growth_stages');

    expect($response->getContent())->not->toContain($attachment->file_path, 'Private review note', 'file_path', 'attachments', 'verified_by');
})->with(['file exists' => true, 'file missing' => false]);

test('prefetched source attachments preserve exclusion rules without database queries', function () {
    Storage::fake(config('filesystems.knowledge_sources_disk'));
    $source = KnowledgeSource::factory()->active()->create(['source_url' => null, 'reference_identifier' => null]);
    $attachment = KnowledgeSourceAttachment::factory()->for($source)->create();
    Storage::disk(config('filesystems.knowledge_sources_disk'))->put($attachment->file_path, 'example');
    $attachments = $source->attachments()->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($source->hasUsableReference(attachments: $attachments))->toBeTrue();
        expect($source->hasUsableReference($attachment->id, $attachments))->toBeFalse();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }
});

test('missing source relationships are diagnosed without creating estimates or activities', function () {
    $context = smartPreviewContext();
    $stage = smartPreviewStage($context);
    $activity = smartPreviewActivity($context);
    DB::statement('PRAGMA defer_foreign_keys = ON');
    try {
        DB::table('crop_stage_timelines')->where('id', $stage->id)->update(['source_id' => 999999]);
        DB::table('crop_activity_templates')->where('id', $activity->id)->update(['source_id' => 999999]);

        $result = app(FarmerSmartTimelineGenerator::class)->generate(smartPreviewInput($context));

        expect($result['growth_stages'])->toBe([]);
        expect($result['activities'])->toBe([]);
        $warnings = collect($result['warnings'])->whereIn('code', ['STAGE_TIMELINE_INELIGIBLE', 'ACTIVITY_TEMPLATE_INELIGIBLE']);
        expect($warnings)->toHaveCount(2);
        foreach ($warnings as $warning) {
            expect($warning['context']->reason)->toBe('source_missing');
        }
    } finally {
        DB::table('crop_stage_timelines')->where('id', $stage->id)->update(['source_id' => $context['source']->id]);
        DB::table('crop_activity_templates')->where('id', $activity->id)->update(['source_id' => $context['source']->id]);
        DB::statement('PRAGMA defer_foreign_keys = OFF');
    }
});

test('unavailable citation storage returns safe diagnostics rather than a server error', function () {
    $context = smartPreviewContext();
    $context['source']->forceFill(['source_url' => null, 'reference_identifier' => null])->save();
    KnowledgeSourceAttachment::factory()->for($context['source'])->create();
    smartPreviewStage($context);
    smartPreviewActivity($context);
    Storage::shouldReceive('disk')->once()->with(config('filesystems.knowledge_sources_disk'))
        ->andThrow(UnableToCheckFileExistence::forLocation('private-source-path'));
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()
        ->assertJsonPath('data.activities', [])->assertJsonPath('data.growth_stages', []);

    expect($response->getContent())->not->toContain('private-source-path');
    $warnings = collect($response->json('data.warnings'))->whereIn('code', ['STAGE_TIMELINE_INELIGIBLE', 'ACTIVITY_TEMPLATE_INELIGIBLE']);
    expect($warnings->pluck('context.reason')->all())->toBe(['source_reference_unavailable', 'source_reference_unavailable']);
});

test('preview normalizes validated numeric IDs and gives X-Locale precedence', function () {
    $context = smartPreviewContext();
    $input = array_map(static fn (mixed $value): string => (string) $value, smartPreviewInput($context));
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->withHeaders(['X-Locale' => 'hi', 'Accept-Language' => 'en'])
        ->postJson('/api/admin/farmer-smart-timeline/preview', $input)->assertOk()
        ->assertJsonPath('data.input', smartPreviewInput($context))->assertJsonPath('data.selection.crop_name', 'गेहूँ');
    $warning = collect($response->json('data.warnings'))->firstWhere('code', 'STAGE_TIMELINE_NOT_FOUND');
    expect($warning['context']['crop_stage_name'])->toBe('अंकुर');
});

test('preview localizes every label and uses header locale with English fallback', function (string $header, string $locale, string $cropName, string $title) {
    config()->set('languages.supported.gu', 'Gujarati');
    $context = smartPreviewContext();
    smartPreviewStage($context);
    $activity = smartPreviewActivity($context);
    foreach (['crop', 'variety', 'season', 'district', 'stage', 'category'] as $key) {
        $context[$key]->translations()->create(['locale' => 'gu', 'name' => 'ગુજરાતી '.$key]);
    }
    $activity->translations()->create(['locale' => 'gu', 'title' => 'ગુજરાતી કાર્ય', 'instructions' => 'ગુજરાતી સૂચના']);
    $context['source']->translations()->createMany([['locale' => 'hi', 'title' => 'हिंदी स्रोत'], ['locale' => 'gu', 'title' => 'ગુજરાતી સ્રોત']]);
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader($header, $locale)
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()
        ->assertJsonPath('data.selection.crop_name', $cropName)->assertJsonPath('data.activities.0.title', $title);

    if ($locale === 'gu') {
        $response->assertJsonPath('data.selection.crop_variety_name', 'ગુજરાતી variety')
            ->assertJsonPath('data.selection.season_name', 'ગુજરાતી season')->assertJsonPath('data.selection.district_name', 'ગુજરાતી district')
            ->assertJsonPath('data.growth_stages.0.name', 'ગુજરાતી stage')->assertJsonPath('data.activities.0.category_name', 'ગુજરાતી category')
            ->assertJsonPath('data.activities.0.source.title', 'ગુજરાતી સ્રોત')->assertJsonPath('data.activities.0.instructions', 'ગુજરાતી સૂચના');
    }
    if ($locale === 'hi') {
        $response->assertJsonPath('data.selection.crop_variety_name', 'परीक्षण किस्म')->assertJsonPath('data.selection.season_name', 'रबी')
            ->assertJsonPath('data.growth_stages.0.name', 'अंकुर')->assertJsonPath('data.activities.0.category_name', 'निगरानी')
            ->assertJsonPath('data.activities.0.source.title', 'हिंदी स्रोत');
    }
})->with([
    'English' => ['X-Locale', 'en', 'Wheat', 'Inspect field'], 'Hindi' => ['X-Locale', 'hi', 'गेहूँ', 'खेत जाँचें'],
    'Gujarati' => ['X-Locale', 'gu', 'ગુજરાતી crop', 'ગુજરાતી કાર્ય'], 'Accept Language' => ['Accept-Language', 'hi', 'गेहूँ', 'खेत जाँचें'],
    'unsupported' => ['X-Locale', 'fr', 'Wheat', 'Inspect field'],
]);

test('missing requested translations use English and canonical citation titles without crashing', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $context = smartPreviewContext();
    $context['source']->forceFill(['language' => 'hi', 'title' => 'मूल स्रोत'])->save();
    smartPreviewStage($context);
    smartPreviewActivity($context);
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'gu')
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()
        ->assertJsonPath('data.selection.crop_name', 'Wheat')->assertJsonPath('data.activities.0.title', 'Inspect field')
        ->assertJsonPath('data.growth_stages.0.name', 'Seedling')->assertJsonPath('data.activities.0.source.title', 'मूल स्रोत');

    expect(array_column($response->json('data.warnings'), 'code'))->toContain('TRANSLATION_FALLBACK', 'SOURCE_TITLE_FALLBACK');
    $warnings = collect($response->json('data.warnings'));
    expect($warnings->firstWhere('code', 'SOURCE_TITLE_FALLBACK')['context']['source_title'])->toBe('मूल स्रोत');
    $labels = ['crop' => 'Wheat', 'crop_variety' => 'Test variety', 'season' => 'Rabi', 'region' => 'Jaipur',
        'crop_stage' => 'Seedling', 'activity_category' => 'Monitoring', 'crop_activity_template' => 'Inspect field'];
    foreach ($warnings->where('code', 'TRANSLATION_FALLBACK') as $warning) {
        $context = $warning['context'];
        $field = $context['resource_type'] === 'crop_activity_template' ? 'resource_title' : 'resource_name';
        expect($context[$field])->toBe($labels[$context['resource_type']]);
    }
});

test('present translations preserve null descriptions and all supported saved instructions are required', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $context = smartPreviewContext();
    $template = smartPreviewActivity($context);
    $template->translations()->where('locale', 'hi')->update(['description' => null]);
    $admin = AdminUser::factory()->create();
    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()
        ->assertJsonPath('data.activities.0.description', null)->assertJsonCount(1, 'data.activities');

    $template->translations()->create(['locale' => 'gu', 'title' => 'ગુજરાતી કાર્ય', 'instructions' => null]);

    $this->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk()->assertJsonPath('data.activities', []);
});

test('preview query counts stay bounded as translated templates and sources grow', function () {
    $context = smartPreviewContext();
    smartPreviewStage($context);
    smartPreviewActivity($context);
    $generator = app(FarmerSmartTimelineGenerator::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $generator->generate(smartPreviewInput($context));
    $initial = count(DB::getQueryLog());
    DB::disableQueryLog();
    for ($index = 0; $index < 10; $index++) {
        smartPreviewActivity($context, [
            'category_id' => ActivityCategory::factory()->published()->create()->id,
            'source_id' => KnowledgeSource::factory()->active()->create()->id,
        ]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $result = $generator->generate(smartPreviewInput($context));
        expect($result['activities'])->toHaveCount(11);
        expect(count(DB::getQueryLog()))->toBe($initial);
        expect($initial)->toBeLessThan(30);
    } finally {
        DB::disableQueryLog();
    }
});

test('repeated preview requests perform no business writes or job and notification dispatches', function () {
    $context = smartPreviewContext();
    smartPreviewStage($context);
    smartPreviewActivity($context);
    $admin = AdminUser::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $tables = ['crops', 'crop_varieties', 'crop_stages', 'crop_stage_timelines', 'crop_activity_templates', 'crop_activity_template_translations', 'knowledge_sources', 'users'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    Bus::fake();
    Queue::fake();
    Notification::fake();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $first = $this->withToken($token)->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk();
        app('auth')->forgetGuards();
        $second = $this->postJson('/api/admin/farmer-smart-timeline/preview', smartPreviewInput($context))->assertOk();
        $queries = DB::getQueryLog();
        expect($second->json('data'))->toBe($first->json('data'));
        foreach ($queries as $query) {
            $sql = strtolower(ltrim($query['query']));
            if (str_starts_with($sql, 'update "personal_access_tokens"')) {
                continue;
            }
            expect($sql)->toStartWith('select');
        }
    } finally {
        DB::disableQueryLog();
    }
    foreach ($tables as $table) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

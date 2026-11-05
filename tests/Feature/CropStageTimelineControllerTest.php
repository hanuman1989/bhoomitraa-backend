<?php

use App\Http\Resources\Admin\CropStageTimelineResource;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array{crop: Crop, stage: CropStage, variety: CropVariety, season: Season, source: KnowledgeSource} */
function timelineContext(bool $published = false): array
{
    $crop = ($published ? Crop::factory()->published('Wheat', 'गेहूँ') : Crop::factory()->withNames('Wheat', 'गेहूँ'))->create();

    return [
        'crop' => $crop,
        'stage' => CropStage::factory()->for($crop)->withNames('Example stage', 'उदाहरण अवस्था')->create(['is_active' => $published]),
        'variety' => CropVariety::factory()->for($crop)->withNames('Example variety', 'उदाहरण किस्म')->create(['is_active' => $published]),
        'season' => Season::factory()->withNames('Rabi', 'रबी')->create(['is_active' => $published]),
        'source' => ($published ? KnowledgeSource::factory()->active() : KnowledgeSource::factory())->create(['title' => 'Illustrative test publication']),
    ];
}

/** @param array<string, mixed> $context @return array<string, mixed> */
function timelinePayload(array $context, array $overrides = []): array
{
    return array_replace([
        'crop_stage_id' => $context['stage']->id,
        'crop_variety_id' => $context['variety']->id,
        'season_id' => $context['season']->id,
        'region_scope' => 'all',
        'region_ids' => [],
        'days_after_sowing_min' => 0,
        'days_after_sowing_max' => 10,
        'source_id' => $context['source']->id,
    ], $overrides);
}

/** @param array<string, mixed> $context */
function timelineRecord(array $context, array $overrides = []): CropStageTimeline
{
    return CropStageTimeline::factory()->for($context['stage'], 'cropStage')->for($context['source'], 'source')
        ->create(array_replace(['crop_variety_id' => $context['variety']->id, 'season_id' => $context['season']->id], $overrides));
}

function timelineDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('timeline endpoints return 401 without an admin bearer token', function (string $method, string $suffix) {
    $timeline = CropStageTimeline::factory()->create();

    $this->json($method, '/api/admin/crop-stage-timelines'.str_replace('{id}', (string) $timeline->id, $suffix))
        ->assertUnauthorized()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
        ]);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('frontend identities cannot access timeline administration', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/crop-stage-timelines')->assertUnauthorized();
});

test('inactive admins and tokens without admin ability receive 403', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/crop-stage-timelines')->assertForbidden();
})->with(['inactive admin' => [false, ['admin']], 'missing ability' => [true, ['frontend']]]);

test('timeline mutations enforce the crop management gate', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $timeline = CropStageTimeline::factory()->create();
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crop-stage-timelines'.str_replace('{id}', (string) $timeline->id, $suffix), ['is_active' => true])
        ->assertForbidden();

    $this->assertModelExists($timeline);
    $this->assertDatabaseCount('crop_stage_timelines', 1);
})->with([
    'create' => ['POST', ''], 'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('admins create inactive specific timelines with derived crop details and district associations', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $first = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $first->parent);
    $this->travelTo('2026-11-05 09:00:00.123456');

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, [
            'region_scope' => 'specific', 'region_ids' => [$first->id, $second->id],
        ]))
        ->assertCreated()->assertJsonPath('data.crop_id', $context['crop']->id)
        ->assertJsonPath('data.crop_name', 'Wheat')->assertJsonPath('data.crop_stage_name', 'Example stage')
        ->assertJsonPath('data.crop_variety_name', 'Example variety')->assertJsonPath('data.season_name', 'Rabi')
        ->assertJsonPath('data.region_ids', [$first->id, $second->id])
        ->assertJsonPath('data.regions.0.name', 'Jaipur')->assertJsonPath('data.regions.0.parent_id', $first->parent_id)
        ->assertJsonPath('data.source_title', 'Illustrative test publication')->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.days_after_sowing_min', 0)->assertJsonPath('data.days_after_sowing_max', 10)
        ->assertJsonPath('data.created_at', '2026-11-05T09:00:00.000000Z')
        ->assertJsonPath('data.added_at', '5 Nov 2026 09:00 AM')->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $timeline = CropStageTimeline::query()->sole();
    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'crop_stage_id' => $context['stage']->id, 'is_active' => false]);
    $this->assertDatabaseCount('crop_stage_timeline_regions', 2);
    expect($timeline->cropStage->id)->toBe($context['stage']->id);
    expect($context['stage']->timelines->sole()->id)->toBe($timeline->id);
    expect($timeline->cropVariety->id)->toBe($context['variety']->id);
    expect($timeline->season->id)->toBe($context['season']->id);
    expect($timeline->source->id)->toBe($context['source']->id);
    expect($first->cropStageTimelines->sole()->id)->toBe($timeline->id);
});

test('all-region timelines allow omitted optional variety season and regions', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $payload = timelinePayload($context);
    unset($payload['crop_variety_id'], $payload['season_id'], $payload['region_ids']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', $payload)
        ->assertCreated()->assertJsonPath('data.crop_variety_id', null)->assertJsonPath('data.crop_variety_name', null)
        ->assertJsonPath('data.season_id', null)->assertJsonPath('data.season_name', null)
        ->assertJsonPath('data.region_scope', 'all')->assertJsonPath('data.region_ids', []);

    $this->assertDatabaseCount('crop_stage_timeline_regions', 0);
});

test('invalid timeline creation returns 422 without persisting metadata or districts', function (array $changes, string $field) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, $changes))
        ->assertUnprocessable()->assertJsonValidationErrors($field)
        ->assertJsonPath('message', 'Validation failed.')->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('crop_stage_timelines', 0);
    $this->assertDatabaseCount('crop_stage_timeline_regions', 0);
})->with([
    'missing stage' => [['crop_stage_id' => null], 'crop_stage_id'],
    'invalid stage' => [['crop_stage_id' => 999], 'crop_stage_id'],
    'invalid variety' => [['crop_variety_id' => 999], 'crop_variety_id'],
    'invalid season' => [['season_id' => 999], 'season_id'],
    'missing source' => [['source_id' => null], 'source_id'],
    'invalid source' => [['source_id' => 999], 'source_id'],
    'negative minimum' => [['days_after_sowing_min' => -1], 'days_after_sowing_min'],
    'negative maximum' => [['days_after_sowing_max' => -1], 'days_after_sowing_max'],
    'fractional days' => [['days_after_sowing_min' => 1.5], 'days_after_sowing_min'],
    'integer overflow' => [['days_after_sowing_max' => 4294967296], 'days_after_sowing_max'],
    'inverted days' => [['days_after_sowing_min' => 20, 'days_after_sowing_max' => 10], 'days_after_sowing_max'],
    'invalid scope' => [['region_scope' => 'state'], 'region_scope'],
    'specific without districts' => [['region_scope' => 'specific'], 'region_ids'],
    'nonexistent district' => [['region_scope' => 'specific', 'region_ids' => [999]], 'region_ids.0'],
    'null selection' => [['region_ids' => null], 'region_ids'],
    'non-list selection' => [['region_ids' => ['district' => 1]], 'region_ids'],
    'activation bypass' => [['is_active' => true], 'is_active'],
    'duplicate crop field' => [['crop_id' => 1], 'crop_id'],
    'private field' => [['id' => 99], 'id'],
    'unrequested description' => [['description' => 'Not part of this catalogue'], 'description'],
    'unrequested translations' => [['translations' => ['en' => ['name' => 'Other']]], 'translations'],
    'request locale' => [['locale' => 'en'], 'locale'],
]);

test('draft timelines reject varieties from another crop', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $other = CropVariety::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, ['crop_variety_id' => $other->id]))
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');

    $this->assertDatabaseCount('crop_stage_timelines', 0);
});

test('district selections reject non-district geographic levels', function (string $type) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $region = Region::factory()->create(['region_type' => $type]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, [
            'region_scope' => 'specific', 'region_ids' => [$region->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');

    $this->assertDatabaseCount('crop_stage_timelines', 0);
})->with(['country', 'state', 'subdistrict', 'village']);

test('all scope rejects nonempty selections and specific scope rejects duplicate district IDs', function (string $scope, string $field) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $district = timelineDistrict();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, [
            'region_scope' => $scope, 'region_ids' => $scope === 'all' ? [$district->id] : [$district->id, $district->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('crop_stage_timelines', 0);
})->with(['all scope' => ['all', 'region_ids'], 'duplicates' => ['specific', 'region_ids.0']]);

test('unsigned integer boundaries do not introduce an arbitrary agricultural day limit', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, ['days_after_sowing_max' => 4294967295]))
        ->assertCreated()->assertJsonPath('data.days_after_sowing_max', 4294967295);

    $this->assertDatabaseHas('crop_stage_timelines', ['days_after_sowing_max' => 4294967295]);
});

test('partial timing updates preserve omitted districts and reject inconsistent effective ranges', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => 'specific']);
    $district = timelineDistrict();
    $timeline->regions()->attach($district);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['days_after_sowing_min' => 5])
        ->assertOk()->assertJsonPath('data.days_after_sowing_min', 5)->assertJsonPath('data.days_after_sowing_max', 10)
        ->assertJsonPath('data.region_ids', [$district->id])->assertJsonPath('data.is_active', false);
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['days_after_sowing_max' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('days_after_sowing_max');

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'days_after_sowing_min' => 5, 'days_after_sowing_max' => 10]);
    $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $timeline->id, 'region_id' => $district->id]);
});

test('region replacements and scope transitions synchronize only explicitly requested selections', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context);
    $first = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $first->parent);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $url = '/api/admin/crop-stage-timelines/'.$timeline->id;

    $this->withToken($token)->patchJson($url, ['region_scope' => 'specific', 'region_ids' => [$first->id]])
        ->assertOk()->assertJsonPath('data.region_scope', 'specific')->assertJsonPath('data.region_ids', [$first->id]);
    $this->withToken($token)->patchJson($url, ['region_ids' => [$second->id]])
        ->assertOk()->assertJsonPath('data.region_ids', [$second->id]);
    $this->withToken($token)->patchJson($url, ['region_scope' => 'specific'])
        ->assertOk()->assertJsonPath('data.region_ids', [$second->id]);
    $this->withToken($token)->patchJson($url, ['region_scope' => 'all'])
        ->assertOk()->assertJsonPath('data.region_scope', 'all')->assertJsonPath('data.region_ids', []);
    $this->withToken($token)->patchJson($url, ['region_scope' => 'specific'])
        ->assertUnprocessable()->assertJsonValidationErrors('region_ids');

    $this->assertDatabaseCount('crop_stage_timeline_regions', 0);
    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'region_scope' => 'all']);
});

test('specific selections cannot be emptied or added to an effective all-region rule', function (string $scope, array $ids, string $field) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => $scope]);
    $district = timelineDistrict();
    if ($scope === 'specific') {
        $timeline->regions()->attach($district);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['region_ids' => $ids === [] ? [] : [$district->id]])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'region_scope' => $scope]);
})->with(['empty specific' => ['specific', [], 'region_ids'], 'nonempty all' => ['all', [1], 'region_ids']]);

test('updates change optional metadata and source while preserving unrelated values', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context);
    $variety = CropVariety::factory()->for($context['crop'])->withNames()->create();
    $season = Season::factory()->withNames()->create();
    $source = KnowledgeSource::factory()->create(['title' => 'Replacement publication']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, [
        'crop_variety_id' => $variety->id, 'season_id' => $season->id, 'source_id' => $source->id,
    ])->assertOk()->assertJsonPath('data.crop_variety_id', $variety->id)->assertJsonPath('data.season_id', $season->id)
        ->assertJsonPath('data.source_title', 'Replacement publication');
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['crop_variety_id' => null, 'season_id' => null])
        ->assertOk()->assertJsonPath('data.crop_variety_id', null)->assertJsonPath('data.season_id', null)
        ->assertJsonPath('data.days_after_sowing_max', 10);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'crop_variety_id' => null, 'season_id' => null, 'source_id' => $source->id]);
});

test('changing stage validates the retained variety against the resulting crop', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context);
    $otherStage = CropStage::factory()->withNames()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['crop_stage_id' => $otherStage->id])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_variety_id');
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['crop_stage_id' => $otherStage->id, 'crop_variety_id' => null])
        ->assertOk()->assertJsonPath('data.crop_stage_id', $otherStage->id)->assertJsonPath('data.crop_id', $otherStage->crop_id);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'crop_stage_id' => $otherStage->id, 'crop_variety_id' => null]);
});

test('timeline publication and active edits require the existing source reviewer permission', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext(published: true);
    $timeline = timelineRecord($context);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])->assertForbidden();
    $timeline->update(['is_active' => true]);
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['days_after_sowing_max' => 20])->assertForbidden();
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'days_after_sowing_max' => 10, 'is_active' => false]);
});

test('reviewers can publish valid timelines and edit them while retaining status', function () {
    $reviewer = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $timeline = timelineRecord($context);
    $token = $reviewer->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['days_after_sowing_max' => 20])
        ->assertOk()->assertJsonPath('data.days_after_sowing_max', 20)->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'days_after_sowing_max' => 20, 'is_active' => true]);
});

test('publication rejects inactive related records and unverified or unusable sources', function (string $relation, array $attributes, string $field) {
    $reviewer = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $context[$relation]->forceFill($attributes)->save();
    $timeline = timelineRecord($context);

    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'is_active' => false]);
})->with([
    'inactive stage' => ['stage', ['is_active' => false], 'crop_stage_id'],
    'inactive crop' => ['crop', ['is_active' => false], 'crop_stage_id'],
    'inactive variety' => ['variety', ['is_active' => false], 'crop_variety_id'],
    'inactive season' => ['season', ['is_active' => false], 'season_id'],
    'inactive source' => ['source', ['is_active' => false], 'source_id'],
    'pending source' => ['source', ['verification_status' => 'pending'], 'source_id'],
    'rejected source' => ['source', ['verification_status' => 'rejected'], 'source_id'],
    'outdated source' => ['source', ['verification_status' => 'outdated'], 'source_id'],
    'unusable source' => ['source', ['source_url' => null, 'reference_identifier' => null], 'source_id'],
]);

test('drafts may retain inactive districts but publication rejects them', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $district = timelineDistrict();
    $district->update(['is_active' => false]);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, [
        'region_scope' => 'specific', 'region_ids' => [$district->id],
    ]))->assertCreated()->assertJsonPath('data.is_active', false);

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$response->json('data.id').'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');

    $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $response->json('data.id'), 'region_id' => $district->id]);
});

test('publication rejects invalid stored day ranges and region scope inconsistencies', function (array $attributes, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $timeline = timelineRecord($context, $attributes);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'is_active' => false]);
})->with([
    'inverted range' => [['days_after_sowing_min' => 20, 'days_after_sowing_max' => 10], 'days_after_sowing_max'],
    'negative range' => [['days_after_sowing_min' => -1], 'days_after_sowing_min'],
    'empty district scope' => [['region_scope' => 'specific'], 'region_ids'],
    'unknown scope' => [['region_scope' => 'state'], 'region_scope'],
]);

test('active metadata edits cannot bypass source verification or district eligibility', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $timeline = timelineRecord($context, ['is_active' => true]);
    $unverified = KnowledgeSource::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, ['source_id' => $unverified->id])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'source_id' => $context['source']->id, 'is_active' => true]);
});

test('invalid status payloads and unrelated changes return 422 without updating timelines', function (array $payload, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $timeline = CropStageTimeline::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'is_active' => false]);
})->with([
    'missing status' => [[], 'is_active'],
    'invalid status' => [['is_active' => 'yes'], 'is_active'],
    'region mutation' => [['is_active' => true, 'region_ids' => []], 'region_ids'],
    'source mutation' => [['is_active' => true, 'source_id' => 999], 'source_id'],
]);

test('publication returns 409 for ambiguous active coverage even when timing ranges differ', function (array $existingChanges, array $candidateChanges) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $district = timelineDistrict();
    $existing = timelineRecord($context, array_replace(['is_active' => true, 'region_scope' => 'specific'], $existingChanges));
    $candidate = timelineRecord($context, array_replace([
        'region_scope' => 'specific', 'days_after_sowing_min' => 100, 'days_after_sowing_max' => 200,
    ], $candidateChanges));
    if ($existing->region_scope === 'specific') {
        $existing->regions()->attach($district);
    }
    if ($candidate->region_scope === 'specific') {
        $candidate->regions()->attach($district);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$candidate->id.'/status', ['is_active' => true])
        ->assertConflict()->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Active timeline '.$existing->id.' has overlapping stage, variety, season, and geographic coverage. Deactivate or revise the conflicting rule before publication.');

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $candidate->id, 'is_active' => false]);
})->with([
    'same selected district' => [[], []],
    'existing all regions' => [['region_scope' => 'all'], []],
    'candidate all regions' => [[], ['region_scope' => 'all']],
    'existing general variety' => [['crop_variety_id' => null], []],
    'candidate general variety' => [[], ['crop_variety_id' => null]],
    'existing general season' => [['season_id' => null], []],
    'candidate general season' => [[], ['season_id' => null]],
]);

test('publication permits disjoint variety season or stage restrictions', function (string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    timelineRecord($context, ['is_active' => true]);
    $related = match ($field) {
        'crop_variety_id' => CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true]),
        'season_id' => Season::factory()->withNames('Other season', 'अन्य मौसम')->create(['is_active' => true]),
        'crop_stage_id' => CropStage::factory()->for($context['crop'])->withNames()->create(['sequence' => 2, 'is_active' => true]),
    };
    $candidate = timelineRecord($context, [$field => $related->id]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$candidate->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $candidate->id, 'is_active' => true]);
})->with(['crop_variety_id', 'season_id', 'crop_stage_id']);

test('disjoint district rules may both publish and newly created districts do not expand saved coverage', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $first = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $first->parent);
    $existing = timelineRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $existing->regions()->attach($first);
    $candidate = timelineRecord($context, ['region_scope' => 'specific']);
    $candidate->regions()->attach($second);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$candidate->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.region_ids', [$second->id]);
    timelineDistrict('Ajmer', $first->parent);
    $this->withToken($token)->getJson('/api/admin/crop-stage-timelines/'.$candidate->id)
        ->assertOk()->assertJsonPath('data.region_ids', [$second->id]);

    $this->assertDatabaseCount('crop_stage_timeline_regions', 2);
});

test('overlapping inactive drafts do not prevent publication', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    timelineRecord($context);
    $candidate = timelineRecord($context, ['crop_variety_id' => null, 'season_id' => null]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$candidate->id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true)->assertJsonPath('data.crop_variety_id', null)->assertJsonPath('data.season_id', null);

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $candidate->id, 'is_active' => true]);
});

test('ambiguous active edits return 409 and preserve the previous metadata and district associations', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $first = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $first->parent);
    $existing = timelineRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $existing->regions()->attach($first);
    $candidate = timelineRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $candidate->regions()->attach($second);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$candidate->id, ['region_scope' => 'all', 'days_after_sowing_max' => 50])
        ->assertConflict();

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $candidate->id, 'region_scope' => 'specific', 'days_after_sowing_max' => 10, 'is_active' => true]);
    $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $candidate->id, 'region_id' => $second->id]);
    $this->assertDatabaseCount('crop_stage_timeline_regions', 2);
});

test('publication rejects legacy all-region rules that still contain district associations', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = timelineContext(published: true);
    $timeline = timelineRecord($context);
    $timeline->regions()->attach(timelineDistrict());

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('region_ids');

    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'is_active' => false]);
});

test('list searches match crop stage variety and source names with literal wildcard escaping', function (string $keyword, string $locale) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $context['source']->update(['title' => 'Publication 50%_!']);
    $match = timelineRecord($context);
    CropStageTimeline::factory()
        ->for(CropStage::factory()->withNames('Different stage', 'अलग अवस्था'), 'cropStage')
        ->for(KnowledgeSource::factory()->state(['title' => 'Other publication 500abc!']), 'source')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->getJson('/api/admin/crop-stage-timelines?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('pagination.total', 1);
})->with([
    'crop' => ['Wheat', 'en'],
    'stage' => ['Example stage', 'en'],
    'variety' => ['Example variety', 'en'],
    'Hindi stage' => ['उदाहरण अवस्था', 'hi'],
    'source and literal wildcards' => ['50%_!', 'en'],
]);

test('list filters isolate crop stage variety season and status without duplicate results', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $match = timelineRecord($context, ['is_active' => true]);
    timelineRecord($context);
    CropStageTimeline::factory()->create();
    $query = [
        'crop_id' => $context['crop']->id, 'crop_stage_id' => $context['stage']->id,
        'crop_variety_id' => $context['variety']->id, 'season_id' => $context['season']->id, 'status' => 1,
    ];
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/crop-stage-timelines?'.http_build_query($query))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id)->assertJsonPath('pagination.total', 1);
    $this->withToken($token)->getJson('/api/admin/crop-stage-timelines?'.http_build_query([...$query, 'status' => 0]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_active', false);
});

test('timeline pagination orders newest first with stable IDs and keeps only allowed filters', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $older = timelineRecord($context, ['created_at' => '2026-11-01 09:00:00']);
    $firstSameTime = timelineRecord($context, ['created_at' => '2026-11-05 09:00:00']);
    $latest = timelineRecord($context, ['created_at' => '2026-11-05 09:00:00']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    $query = ['crop_id' => $context['crop']->id, 'status' => 0, 'keyword' => 'गेहूँ', 'per_page' => 1];

    $response = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stage-timelines?'.http_build_query([...$query, 'locale' => 'xx', 'ignored' => 1]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $latest->id)
        ->assertJsonPath('data.0.crop_name', 'गेहूँ')->assertJsonPath('data.0.crop_stage_name', 'उदाहरण अवस्था')
        ->assertJsonMissingPath('data.0.regions')->assertJsonMissingPath('data.0.region_ids')->assertJsonMissingPath('data.0.region_scope')
        ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.current_page', 1)
        ->assertJsonPath('pagination.last_page', 3);

    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$context['crop']->id, 'status=0', 'keyword='.rawurlencode('गेहूँ'), 'per_page=1')
        ->not->toContain('locale=', 'ignored=');
    expect(array_keys($response->json('pagination')))->toBe([
        'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'next_page_url', 'prev_page_url',
    ]);
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonPath('data.0.id', $firstSameTime->id);
    $this->getJson('/api/admin/crop-stage-timelines?'.http_build_query([...$query, 'page' => 3]))
        ->assertOk()->assertJsonPath('data.0.id', $older->id);
});

test('empty timeline pages and default page size follow the shared pagination contract', function () {
    $admin = AdminUser::factory()->create();
    CropStageTimeline::factory()->create();
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/crop-stage-timelines')->assertOk()->assertJsonPath('pagination.per_page', 15);
    $this->getJson('/api/admin/crop-stage-timelines?page=2&per_page=1')->assertOk()->assertJsonPath('data', [])
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null)->assertJsonPath('pagination.total', 1);
});

test('invalid timeline list filters return 422', function (array $query, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-stage-timelines?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page' => [['page' => 0], 'page'],
    'too many rows' => [['per_page' => 101], 'per_page'],
    'zero rows' => [['per_page' => 0], 'per_page'],
    'invalid crop' => [['crop_id' => 999], 'crop_id'],
    'invalid stage' => [['crop_stage_id' => 999], 'crop_stage_id'],
    'invalid variety' => [['crop_variety_id' => 999], 'crop_variety_id'],
    'invalid season' => [['season_id' => 999], 'season_id'],
    'invalid status' => [['status' => 'active'], 'status'],
    'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'],
]);

test('detail responses resolve locale headers and English fallback without exposing source attachments', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => 'specific']);
    $context['source']->translations()->createMany([
        ['locale' => 'en', 'title' => 'English publication title'],
        ['locale' => 'hi', 'title' => 'हिन्दी प्रकाशन'],
    ]);
    $district = timelineDistrict();
    $district->translations()->create(['locale' => 'hi', 'name' => 'जयपुर']);
    $timeline->regions()->attach($district);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-stage-timelines/'.$timeline->id.'?locale=en')
        ->assertOk()->assertJsonPath('data.crop_name', 'गेहूँ')->assertJsonPath('data.crop_stage_name', 'उदाहरण अवस्था')
        ->assertJsonPath('data.crop_variety_name', 'उदाहरण किस्म')->assertJsonPath('data.season_name', 'रबी')
        ->assertJsonPath('data.source_title', 'हिन्दी प्रकाशन')
        ->assertJsonPath('data.regions.0.name', 'जयपुर')->assertJsonMissingPath('data.attachments')
        ->assertJsonMissingPath('data.source.file_path')->assertJsonMissingPath('data.source_url');
    $this->withToken($token)->withHeader('X-Locale', 'gu')->getJson('/api/admin/crop-stage-timelines/'.$timeline->id)
        ->assertOk()->assertJsonPath('data.crop_stage_name', 'Example stage')->assertJsonPath('data.regions.0.name', 'Jaipur')
        ->assertJsonPath('data.source_title', 'English publication title');
});

test('missing timeline responses use the JSON envelope without an Accept header', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->get('/api/admin/crop-stage-timelines/999')->assertNotFound()
        ->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.']);
});

test('timeline resources do not query unloaded relationships', function () {
    $timeline = CropStageTimeline::factory()->make();
    DB::enableQueryLog();

    try {
        $data = (new CropStageTimelineResource($timeline))->resolve(Request::create('/'));

        expect($data)->not->toHaveKeys(['regions', 'region_ids', 'crop_name', 'crop_stage_name', 'source_title']);
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('referenced timelines retain historical records and districts through deactivation and rejected deletion', function (string $deleteAction) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $district = timelineDistrict();
    $timeline->regions()->attach($district);
    Schema::create('timeline_history_records', function (Blueprint $table) use ($deleteAction): void {
        $table->id();
        $table->unsignedBigInteger('crop_stage_timeline_id')->nullable();
        $table->date('predicted_on');
        $table->foreign('crop_stage_timeline_id')->references('id')->on('crop_stage_timelines')->onDelete($deleteAction);
    });
    DB::table('timeline_history_records')->insert(['crop_stage_timeline_id' => $timeline->id, 'predicted_on' => '2025-10-15']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    try {
        $this->withToken($token)->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id.'/status', ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.region_ids', [$district->id]);
        $this->withToken($token)->deleteJson('/api/admin/crop-stage-timelines/'.$timeline->id)
            ->assertConflict()->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'The stage timeline cannot be deleted while active or referenced by other records. Deactivate it instead.',
            ]);
        $this->withToken($token)->getJson('/api/admin/crop-stage-timelines/'.$timeline->id)
            ->assertOk()->assertJsonPath('data.crop_stage_name', 'Example stage');
        $this->assertDatabaseHas('timeline_history_records', ['crop_stage_timeline_id' => $timeline->id, 'predicted_on' => '2025-10-15']);
        $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $timeline->id, 'region_id' => $district->id]);
    } finally {
        Schema::dropIfExists('timeline_history_records');
    }
})->with(['restrict' => ['restrict'], 'cascade' => ['cascade'], 'set null' => ['set null']]);

test('active timelines must be deactivated before deletion', function () {
    $admin = AdminUser::factory()->create();
    $timeline = CropStageTimeline::factory()->create(['is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-stage-timelines/'.$timeline->id)->assertConflict();

    $this->assertModelExists($timeline);
});

test('unused inactive timelines delete only their own district pivot records', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => 'specific']);
    $other = timelineRecord($context, ['region_scope' => 'specific']);
    $district = timelineDistrict();
    $timeline->regions()->attach($district);
    $other->regions()->attach($district);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-stage-timelines/'.$timeline->id)
        ->assertOk()->assertExactJson(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Stage timeline deleted successfully.']);

    $this->assertModelMissing($timeline);
    $this->assertModelExists($other);
    $this->assertModelExists($district);
    foreach ($context as $model) {
        $this->assertModelExists($model);
    }
    $this->assertDatabaseCount('crop_stage_timeline_regions', 1);
    $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $other->id, 'region_id' => $district->id]);
});

test('existing catalogue endpoints cannot delete timeline dependencies', function (string $resource, string $contextKey) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    timelineRecord($context);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/'.$resource.'/'.$context[$contextKey]->id)->assertConflict();

    $this->assertModelExists($context[$contextKey]);
    $this->assertDatabaseCount('crop_stage_timelines', 1);
})->with([
    'growth stage' => ['crop-stages', 'stage'],
    'variety' => ['crop-varieties', 'variety'],
    'season' => ['seasons', 'season'],
    'source' => ['knowledge-sources', 'source'],
]);

test('referenced districts cannot be deleted through Region Manager', function () {
    $admin = AdminUser::factory()->create();
    $timeline = CropStageTimeline::factory()->create(['region_scope' => 'specific']);
    $district = timelineDistrict();
    $timeline->regions()->attach($district);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/regions/'.$district->id)->assertConflict();

    $this->assertModelExists($district);
    $this->assertDatabaseCount('crop_stage_timeline_regions', 1);
});

test('the pivot composite key rejects duplicate associations and invalid foreign keys', function () {
    $timeline = CropStageTimeline::factory()->create(['region_scope' => 'specific']);
    $district = timelineDistrict();
    $timeline->regions()->attach($district);

    expect(fn () => $timeline->regions()->attach($district))->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $timeline->regions()->attach(999))->toThrow(QueryException::class);

    $this->assertDatabaseCount('crop_stage_timeline_regions', 1);
});

test('failed district creation rolls back the new timeline and all pivot writes', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $first = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $first->parent);
    DB::unprepared('CREATE TRIGGER fail_timeline_region_insert BEFORE INSERT ON crop_stage_timeline_regions FOR EACH ROW WHEN NEW.region_id = '.$second->id." BEGIN SELECT RAISE(ABORT, 'Simulated district persistence failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context, [
                'region_scope' => 'specific', 'region_ids' => [$first->id, $second->id],
            ]))->assertInternalServerError()->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'Unable to create the stage timeline. Please try again later.',
            ]);

        $this->assertDatabaseCount('crop_stage_timelines', 0);
        $this->assertDatabaseCount('crop_stage_timeline_regions', 0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_timeline_region_insert');
    }
});

test('failed district replacement rolls back timing changes detached districts and inserted districts', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $timeline = timelineRecord($context, ['region_scope' => 'specific']);
    $original = timelineDistrict();
    $second = timelineDistrict('Bhilwara', $original->parent);
    $third = timelineDistrict('Ajmer', $original->parent);
    $timeline->regions()->attach($original);
    DB::unprepared('CREATE TRIGGER fail_timeline_region_update BEFORE INSERT ON crop_stage_timeline_regions FOR EACH ROW WHEN NEW.region_id = '.$third->id." BEGIN SELECT RAISE(ABORT, 'Simulated district persistence failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson('/api/admin/crop-stage-timelines/'.$timeline->id, [
                'days_after_sowing_max' => 50, 'region_ids' => [$second->id, $third->id],
            ])->assertInternalServerError()->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'Unable to update the stage timeline. Please try again later.',
            ]);

        $this->assertDatabaseHas('crop_stage_timelines', ['id' => $timeline->id, 'days_after_sowing_max' => 10]);
        $this->assertDatabaseCount('crop_stage_timeline_regions', 1);
        $this->assertDatabaseHas('crop_stage_timeline_regions', ['crop_stage_timeline_id' => $timeline->id, 'region_id' => $original->id]);
    } finally {
        DB::unprepared('DROP TRIGGER fail_timeline_region_update');
    }
});

test('timeline collections eager load related catalogues once and never load region trees for lists', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    timelineRecord($context);
    timelineRecord($context);
    timelineRecord($context);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->getJson('/api/admin/crop-stage-timelines')->assertOk()->assertJsonCount(3, 'data');

        foreach (['crop_stages', 'crops', 'crop_varieties', 'seasons', 'crop_stage_translations', 'crop_translations', 'crop_variety_translations', 'season_translations', 'knowledge_source_translations'] as $table) {
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'select * from "'.$table.'"'));
            expect($queries)->toHaveCount(1);
        }
        $regionQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], ' from "regions"'));
        expect($regionQueries)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('existing Region APIs provide filtered state and district selector data', function () {
    $admin = AdminUser::factory()->create();
    $district = timelineDistrict();
    $state = $district->parent;
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->getJson('/api/admin/regions?region_type=state&is_active=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $state->id);
    $this->withToken($token)->getJson('/api/admin/regions?parent_id='.$state->id.'&region_type=district&is_active=1')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $district->id);
});

test('timeline listings use locale headers for every related catalogue name', function (array $headers, array $expectedNames) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $context['crop']->translations()->create(['locale' => 'gu', 'name' => 'ઘઉં']);
    $context['stage']->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ અવસ્થા']);
    $context['variety']->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ જાત']);
    $context['season']->translations()->create(['locale' => 'gu', 'name' => 'રબી']);
    $context['source']->translations()->createMany([
        ['locale' => 'en', 'title' => 'English publication title'],
        ['locale' => 'hi', 'title' => 'हिन्दी प्रकाशन'],
        ['locale' => 'gu', 'title' => 'ગુજરાતી પ્રકાશન'],
    ]);
    $timeline = timelineRecord($context);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/crop-stage-timelines?locale=en')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $timeline->id);
    foreach ($expectedNames as $field => $name) {
        $response->assertJsonPath('data.0.'.$field, $name);
    }
})->with([
    'English default' => [[], ['crop_name' => 'Wheat', 'crop_stage_name' => 'Example stage', 'crop_variety_name' => 'Example variety', 'season_name' => 'Rabi', 'source_title' => 'English publication title']],
    'Hindi locale header' => [['X-Locale' => 'hi'], ['crop_name' => 'गेहूँ', 'crop_stage_name' => 'उदाहरण अवस्था', 'crop_variety_name' => 'उदाहरण किस्म', 'season_name' => 'रबी', 'source_title' => 'हिन्दी प्रकाशन']],
    'Hindi language header' => [['Accept-Language' => 'hi'], ['crop_name' => 'गेहूँ', 'crop_stage_name' => 'उदाहरण अवस्था', 'crop_variety_name' => 'उदाहरण किस्म', 'season_name' => 'रबी', 'source_title' => 'हिन्दी प्रकाशन']],
    'configured Gujarati' => [['X-Locale' => 'gu'], ['crop_name' => 'ઘઉં', 'crop_stage_name' => 'ઉદાહરણ અવસ્થા', 'crop_variety_name' => 'ઉદાહરણ જાત', 'season_name' => 'રબી', 'source_title' => 'ગુજરાતી પ્રકાશન']],
    'locale header precedence' => [['X-Locale' => 'en', 'Accept-Language' => 'hi'], ['crop_name' => 'Wheat', 'source_title' => 'English publication title']],
    'unsupported locale fallback' => [['X-Locale' => 'fr'], ['crop_name' => 'Wheat', 'source_title' => 'English publication title']],
]);

test('timeline lists fall back independently for missing translations and retain nullable related names', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $context['crop']->translations()->where('locale', 'hi')->delete();
    $context['variety']->translations()->where('locale', 'hi')->delete();
    $context['season']->translations()->where('locale', 'hi')->delete();
    $timeline = timelineRecord($context);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->withHeader('X-Locale', 'hi')->getJson('/api/admin/crop-stage-timelines')
        ->assertOk()->assertJsonPath('data.0.crop_name', 'Wheat')
        ->assertJsonPath('data.0.crop_stage_name', 'उदाहरण अवस्था')
        ->assertJsonPath('data.0.crop_variety_name', 'Example variety')
        ->assertJsonPath('data.0.season_name', 'Rabi')
        ->assertJsonPath('data.0.source_title', 'Illustrative test publication');

    $timeline->update(['crop_variety_id' => null, 'season_id' => null]);
    $this->getJson('/api/admin/crop-stage-timelines')->assertOk()
        ->assertJsonPath('data.0.crop_variety_name', null)->assertJsonPath('data.0.season_name', null);
});

test('timeline writes return localized related names without changing translation content', function () {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $context['source']->translations()->create(['locale' => 'hi', 'title' => 'हिन्दी प्रकाशन']);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $created = $this->withToken($token)->withHeader('X-Locale', 'hi')
        ->postJson('/api/admin/crop-stage-timelines', timelinePayload($context))->assertCreated();
    $path = '/api/admin/crop-stage-timelines/'.$created->json('data.id');
    $updated = $this->patchJson($path, ['days_after_sowing_max' => 20])->assertOk();
    $status = $this->patchJson($path.'/status', ['is_active' => false])->assertOk();

    foreach ([$created, $updated, $status] as $response) {
        $response->assertJsonPath('data.crop_name', 'गेहूँ')->assertJsonPath('data.crop_stage_name', 'उदाहरण अवस्था')
            ->assertJsonPath('data.crop_variety_name', 'उदाहरण किस्म')->assertJsonPath('data.season_name', 'रबी')
            ->assertJsonPath('data.source_title', 'हिन्दी प्रकाशन');
    }
    $this->assertDatabaseHas('crop_stage_timelines', ['id' => $created->json('data.id'), 'days_after_sowing_max' => 20]);
    $this->assertDatabaseCount('knowledge_source_translations', 1);
});

test('timeline searches use resolved related names and titles with literal wildcard escaping', function (string $keyword, int $expectedCount) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    $context['source']->translations()->createMany([
        ['locale' => 'en', 'title' => 'English reference'],
        ['locale' => 'hi', 'title' => 'हिन्दी संदर्भ 50%_!'],
    ]);
    $context['variety']->translations()->where('locale', 'hi')->delete();
    timelineRecord($context);
    CropStageTimeline::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stage-timelines?'.http_build_query(['keyword' => $keyword, 'per_page' => 1]))
        ->assertOk()->assertJsonCount($expectedCount, 'data')->assertJsonPath('pagination.total', $expectedCount);
})->with([
    'localized crop' => ['गेहूँ', 1],
    'hidden English crop' => ['Wheat', 0],
    'variety English fallback' => ['Example variety', 1],
    'localized season' => ['रबी', 1],
    'localized title' => ['हिन्दी संदर्भ', 1],
    'literal title wildcard characters' => ['50%_!', 1],
    'escaped wildcard must not match' => ['50%_x', 0],
    'hidden English title' => ['English reference', 0],
    'hidden canonical title' => ['Illustrative test publication', 0],
]);

test('timeline title search follows English translation then original citation fallbacks', function (bool $hasEnglishTitle, string $keyword) {
    $admin = AdminUser::factory()->create();
    $context = timelineContext();
    if ($hasEnglishTitle) {
        $context['source']->translations()->create(['locale' => 'en', 'title' => 'English reference']);
    }
    timelineRecord($context);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-stage-timelines?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_title', $keyword);
})->with([
    'English translation' => [true, 'English reference'],
    'original citation' => [false, 'Illustrative test publication'],
]);

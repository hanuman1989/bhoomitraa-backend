<?php

use App\Http\Resources\Admin\CropActivityTemplateResource;
use App\Models\ActivityCategory;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
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

/** @return array{crop: Crop, variety: CropVariety, category: ActivityCategory, stage: CropStage, season: Season, source: KnowledgeSource} */
function activityTemplateContext(bool $published = false): array
{
    $crop = Crop::factory()->published('Wheat', 'गेहूँ')->create();

    return [
        'crop' => $crop,
        'variety' => CropVariety::factory()->for($crop)->withNames('Example variety', 'उदाहरण किस्म')->create(['is_active' => $published]),
        'category' => ActivityCategory::factory()->withNames('Crop monitoring', 'फसल निगरानी')->create(['is_active' => true]),
        'stage' => CropStage::factory()->for($crop)->withNames('Example stage', 'उदाहरण अवस्था')->create(['is_active' => $published]),
        'season' => Season::factory()->withNames('Rabi', 'रबी')->create(['is_active' => $published]),
        'source' => ($published ? KnowledgeSource::factory()->active() : KnowledgeSource::factory())->create(['title' => 'Illustrative test publication']),
    ];
}

/** @param array<string, mixed> $context @return array<string, mixed> */
function activityTemplatePayload(array $context, array $overrides = []): array
{
    return array_replace([
        'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id,
        'category_id' => $context['category']->id, 'crop_stage_id' => $context['stage']->id,
        'season_id' => $context['season']->id, 'source_id' => $context['source']->id,
        'schedule_type' => 'after_sowing', 'start_offset_days' => 0, 'end_offset_days' => 10,
        'region_scope' => 'all', 'region_ids' => [],
        'translations' => [
            'en' => ['title' => 'Inspect field conditions', 'description' => 'Illustrative monitoring activity.', 'instructions' => 'Illustrative test instructions.'],
            'hi' => ['title' => 'खेत की स्थिति की जाँच करें', 'description' => 'उदाहरण गतिविधि।', 'instructions' => 'परीक्षण के उदाहरण निर्देश।'],
        ],
    ], $overrides);
}

/** @param array<string, mixed> $context */
function activityTemplateRecord(array $context, array $overrides = []): CropActivityTemplate
{
    return CropActivityTemplate::factory()->for($context['crop'], 'crop')->for($context['category'], 'category')
        ->for($context['season'], 'season')->for($context['source'], 'source')->withTitles('Inspect field conditions', 'खेत की स्थिति की जाँच करें')
        ->create(array_replace(['crop_variety_id' => $context['variety']->id, 'crop_stage_id' => $context['stage']->id], $overrides));
}

function activityTemplateDistrict(string $name = 'Jaipur', ?Region $state = null): Region
{
    $state ??= Region::factory()->under(Region::factory()->withEnglishName('India')->create())->withEnglishName('Rajasthan')->create();

    return Region::factory()->under($state)->withEnglishName($name)->create();
}

test('crop activity template endpoints return 401 without an admin bearer token', function (string $method, string $suffix) {
    $template = CropActivityTemplate::factory()->withTitles()->create();

    $this->json($method, '/api/admin/crop-activity-templates'.str_replace('{id}', (string) $template->id, $suffix))
        ->assertUnauthorized()->assertExactJson(['status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.']);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('frontend identities cannot administer crop activity templates', function () {
    $user = User::factory()->create();

    $this->withToken($user->createToken('test', ['*'])->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates')->assertUnauthorized();
});

test('inactive admins and tokens without admin ability cannot access crop activity templates', function (bool $active, array $abilities) {
    $admin = AdminUser::factory()->create(['status' => $active]);

    $this->withToken($admin->createToken('test', $abilities)->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates')->assertForbidden();
})->with(['inactive' => [false, ['admin']], 'wrong ability' => [true, ['frontend']]]);

test('crop management denial protects all template operations', function (string $method, string $suffix) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    Gate::define('manage-crops', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crop-activity-templates'.str_replace('{id}', (string) $template->id, $suffix), activityTemplatePayload($context))
        ->assertForbidden();

    $this->assertModelExists($template);
    $this->assertDatabaseCount('crop_activity_templates', 1);
})->with([
    'list' => ['GET', ''], 'create' => ['POST', ''], 'detail' => ['GET', '/{id}'],
    'update' => ['PATCH', '/{id}'], 'delete' => ['DELETE', '/{id}'], 'status' => ['PATCH', '/{id}/status'],
]);

test('admins create inactive templates with medium priority and all configured translations and districts', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $this->travelTo('2026-11-06 09:00:00.123456');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $district = activityTemplateDistrict();
    $second = activityTemplateDistrict('Ajmer', $district->parent);
    $payload = activityTemplatePayload($context, ['region_scope' => 'specific', 'region_ids' => [$district->id, $second->id]]);
    $payload['translations']['gu'] = ['title' => 'ખેતરની સ્થિતિ તપાસો', 'description' => '', 'instructions' => null];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', $payload)->assertCreated()
        ->assertJsonPath('data.is_active', false)->assertJsonPath('data.priority', 'medium')
        ->assertJsonPath('data.title', 'Inspect field conditions')->assertJsonPath('data.crop_name', 'Wheat')
        ->assertJsonPath('data.category_name', 'Crop monitoring')->assertJsonPath('data.source_title', 'Illustrative test publication')
        ->assertJsonPath('data.region_ids', [$district->id, $second->id])
        ->assertJsonPath('data.translations.hi.title', 'खेत की स्थिति की जाँच करें')
        ->assertJsonPath('data.translations.gu.description', null)->assertJsonPath('data.translations.gu.instructions', null)
        ->assertJsonPath('data.created_at', '2026-11-06T09:00:00.123456Z')->assertJsonPath('data.added_at', '6 Nov 2026 09:00 AM')
        ->assertJsonPath('pagination', null)->assertJsonPath('data.publication_warnings', []);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('crop_activity_templates', ['id' => $response->json('data.id'), 'crop_id' => $context['crop']->id, 'season_id' => $context['season']->id, 'is_active' => false, 'priority' => 'medium']);
    $this->assertDatabaseCount('crop_activity_template_translations', 3);
    $this->assertDatabaseCount('crop_activity_template_regions', 2);
    $template = CropActivityTemplate::findOrFail($response->json('data.id'));
    expect($template->crop->is($context['crop']))->toBeTrue();
    expect($template->cropVariety->is($context['variety']))->toBeTrue();
    expect($template->category->is($context['category']))->toBeTrue();
    expect($template->cropStage->is($context['stage']))->toBeTrue();
    expect($template->season->is($context['season']))->toBeTrue();
    expect($template->source->is($context['source']))->toBeTrue();
    expect($template->translations->first()->cropActivityTemplate->is($template))->toBeTrue();
    expect($district->cropActivityTemplates->modelKeys())->toBe([$template->id]);
});

test('general templates allow null variety and stage and zero length after sowing windows', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, [
            'crop_variety_id' => null, 'crop_stage_id' => null, 'start_offset_days' => 0, 'end_offset_days' => 0,
        ]))->assertCreated()->assertJsonPath('data.crop_variety_name', null)->assertJsonPath('data.crop_stage_name', null)
        ->assertJsonPath('data.region_ids', [])->assertJsonPath('data.start_offset_days', 0)->assertJsonPath('data.end_offset_days', 0);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $response->json('data.id'), 'crop_variety_id' => null, 'crop_stage_id' => null]);
    $this->assertDatabaseCount('crop_activity_template_regions', 0);
});

test('template creation reports missing fields using the standard nested validation envelope', function () {
    $admin = AdminUser::factory()->create();

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['crop_id', 'category_id', 'season_id', 'source_id', 'schedule_type', 'start_offset_days', 'end_offset_days', 'region_scope'])
        ->assertJsonValidationErrors([
            'translations.en.title' => 'The English activity title is required.',
            'translations.hi.title' => 'The Hindi activity title is required.',
        ])->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('crop_activity_templates', 0);
});

test('invalid template create payloads return 422 without partial writes', function (array $overrides, string $field) {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, $overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('crop_activity_templates', 0);
    $this->assertDatabaseCount('crop_activity_template_translations', 0);
    $this->assertDatabaseCount('crop_activity_template_regions', 0);
})->with([
    'missing crop' => [['crop_id' => 99999], 'crop_id'],
    'missing variety' => [['crop_variety_id' => 99999], 'crop_variety_id'],
    'missing category' => [['category_id' => 99999], 'category_id'],
    'missing stage' => [['crop_stage_id' => 99999], 'crop_stage_id'],
    'missing season' => [['season_id' => 99999], 'season_id'],
    'null season' => [['season_id' => null], 'season_id'],
    'missing source' => [['source_id' => 99999], 'source_id'],
    'negative identifier' => [['crop_id' => -1], 'crop_id'],
    'unknown anchor' => [['schedule_type' => 'stage_start'], 'schedule_type'],
    'null start' => [['start_offset_days' => null], 'start_offset_days'],
    'negative start' => [['start_offset_days' => -1], 'start_offset_days'],
    'fractional offset' => [['start_offset_days' => 0.5], 'start_offset_days'],
    'negative end' => [['end_offset_days' => -1], 'end_offset_days'],
    'end before start' => [['start_offset_days' => 11], 'end_offset_days'],
    'storage overflow' => [['end_offset_days' => 2147483648], 'end_offset_days'],
    'unknown priority' => [['priority' => 'urgent'], 'priority'],
    'null priority' => [['priority' => null], 'priority'],
    'unknown region scope' => [['region_scope' => 'state'], 'region_scope'],
    'specific without IDs' => [['region_scope' => 'specific'], 'region_ids'],
    'all with IDs' => [['region_ids' => [99999]], 'region_ids'],
    'null region IDs' => [['region_ids' => null], 'region_ids'],
    'region IDs must be list' => [['region_ids' => ['district' => 1]], 'region_ids'],
    'invalid district ID' => [['region_scope' => 'specific', 'region_ids' => [99999]], 'region_ids.0'],
    'negative district ID' => [['region_scope' => 'specific', 'region_ids' => [-1]], 'region_ids.0'],
    'duplicate districts' => [['region_scope' => 'specific', 'region_ids' => [1, '1']], 'region_ids.0'],
    'activation bypass' => [['is_active' => true], 'is_active'],
    'injected ID' => [['id' => 999], 'id'],
    'injected timestamp' => [['created_at' => '2000-01-01'], 'created_at'],
    'injected approval' => [['is_approved' => true], 'is_approved'],
    'injected warnings' => [['potential_overlap_ids' => [1]], 'potential_overlap_ids'],
    'request locale' => [['locale' => 'hi'], 'locale'],
    'null translations' => [['translations' => null], 'translations'],
    'empty translations' => [['translations' => []], 'translations'],
    'missing English title' => [['translations' => ['hi' => ['title' => 'गतिविधि']]], 'translations.en.title'],
    'missing Hindi title' => [['translations' => ['en' => ['title' => 'Activity']]], 'translations.hi.title'],
    'empty English title' => [['translations' => ['en' => ['title' => ' '], 'hi' => ['title' => 'गतिविधि']]], 'translations.en.title'],
    'long title' => [['translations' => ['en' => ['title' => str_repeat('a', 201)], 'hi' => ['title' => 'गतिविधि']]], 'translations.en.title'],
    'unsupported locale' => [['translations' => ['en' => ['title' => 'Activity'], 'hi' => ['title' => 'गतिविधि'], 'fr' => ['title' => 'French']]], 'translations'],
    'nested foreign key' => [['translations' => ['en' => ['title' => 'Activity', 'crop_activity_template_id' => 999], 'hi' => ['title' => 'गतिविधि']]], 'translations.en'],
    'nested review metadata' => [['translations' => ['en' => ['title' => 'Activity'], 'hi' => ['title' => 'गतिविधि', 'review_status' => 'approved']]], 'translations.hi'],
    'description type' => [['translations' => ['en' => ['title' => 'Activity', 'description' => []], 'hi' => ['title' => 'गतिविधि']]], 'translations.en.description'],
    'instructions type' => [['translations' => ['en' => ['title' => 'Activity', 'instructions' => []], 'hi' => ['title' => 'गतिविधि']]], 'translations.en.instructions'],
]);

test('template varieties and stages must belong to the selected crop', function (string $field, string $model) {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $other = $model::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, [$field => $other->id]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('crop_activity_templates', 0);
})->with(['variety' => ['crop_variety_id', CropVariety::class], 'stage' => ['crop_stage_id', CropStage::class]]);

test('new template category selections must be active and released', function (string $state) {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    if ($state === 'inactive') {
        $context['category']->update(['is_active' => false]);
    } else {
        $context['category']->update(['slug' => 'ask-ai-doctor']);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context))
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');

    $this->assertDatabaseCount('crop_activity_templates', 0);
})->with(['inactive', 'unreleased']);

test('template district selections reject other region types and inactive new districts', function (string $regionType, bool $active) {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $district = activityTemplateDistrict();
    $district->update(['region_type' => $regionType, 'is_active' => $active]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, ['region_scope' => 'specific', 'region_ids' => [$district->id]]))
        ->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');

    $this->assertDatabaseCount('crop_activity_template_regions', 0);
})->with(['state' => ['state', true], 'subdistrict' => ['subdistrict', true], 'village' => ['village', true], 'inactive district' => ['district', false]]);

test('template patches preserve omitted metadata translations and districts while allowing independent translated fields', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    $source = KnowledgeSource::factory()->create();
    $path = '/api/admin/crop-activity-templates/'.$template->id;
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson($path, [
        'priority' => 'high', 'source_id' => $source->id, 'end_offset_days' => 20,
        'translations' => ['en' => ['title' => 'Updated activity'], 'hi' => ['description' => 'नया विवरण'], 'gu' => ['title' => 'ઉદાહરણ કાર્ય']],
    ])->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.region_ids', [$district->id])
        ->assertJsonPath('data.translations.en.instructions', 'Illustrative test instructions.')
        ->assertJsonPath('data.translations.hi.title', 'खेत की स्थिति की जाँच करें')
        ->assertJsonPath('data.translations.hi.description', 'नया विवरण')->assertJsonPath('data.translations.gu.title', 'ઉદાહરણ કાર્ય');
    $this->patchJson($path, ['translations' => ['en' => ['instructions' => null]]])->assertOk()
        ->assertJsonPath('data.instructions', null)->assertJsonPath('data.translations.en.title', 'Updated activity')
        ->assertJsonPath('data.translations.gu.title', 'ઉદાહરણ કાર્ય')->assertJsonPath('data.region_ids', [$district->id]);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'crop_id' => $context['crop']->id, 'priority' => 'high', 'source_id' => $source->id, 'end_offset_days' => 20, 'is_active' => false]);
    $this->assertDatabaseCount('crop_activity_template_translations', 3);
    $this->assertDatabaseHas('crop_activity_template_regions', ['crop_activity_template_id' => $template->id, 'region_id' => $district->id]);
});

test('template region patches replace explicit selections and clear them only when changing to all', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    $first = activityTemplateDistrict();
    $second = activityTemplateDistrict('Ajmer', $first->parent);
    $path = '/api/admin/crop-activity-templates/'.$template->id;

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson($path, ['region_scope' => 'specific', 'region_ids' => [$first->id, $second->id]])
        ->assertOk()->assertJsonPath('data.region_ids', [$first->id, $second->id]);
    $this->patchJson($path, ['region_ids' => [$second->id]])->assertOk()->assertJsonPath('data.region_ids', [$second->id]);
    $this->patchJson($path, ['region_scope' => 'all'])->assertOk()->assertJsonPath('data.region_ids', []);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'region_scope' => 'all']);
    $this->assertDatabaseCount('crop_activity_template_regions', 0);
    $this->assertModelExists($first);
    $this->assertModelExists($second);
});

test('invalid effective template patches return 422 and preserve the existing state', function (array $changes, string $field) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['start_offset_days' => 10, 'end_offset_days' => 20]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id, $changes)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'start_offset_days' => 10, 'end_offset_days' => 20, 'region_scope' => 'all', 'is_active' => false]);
    $this->assertDatabaseCount('crop_activity_template_translations', 2);
})->with([
    'effective start too late' => [['start_offset_days' => 21], 'end_offset_days'],
    'effective end too early' => [['end_offset_days' => 9], 'end_offset_days'],
    'unsupported scheduling change' => [['schedule_type' => 'manual'], 'schedule_type'],
    'specific without selection' => [['region_scope' => 'specific'], 'region_ids'],
    'all cannot gain regions' => [['region_ids' => [1]], 'region_ids'],
    'new locale missing title' => [['translations' => ['gu' => ['instructions' => 'Instructions only']]], 'translations.gu.title'],
    'empty optional translation' => [['translations' => ['gu' => []]], 'translations.gu'],
    'remove Hindi title' => [['translations' => ['hi' => ['title' => null]]], 'translations.hi.title'],
    'unsupported locale' => [['translations' => ['fr' => ['title' => 'French']]], 'translations'],
    'publication bypass' => [['is_active' => true], 'is_active'],
    'protected timestamp' => [['updated_at' => '2000-01-01'], 'updated_at'],
]);

test('templates cannot be reassigned to another crop after creation', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    $otherCrop = Crop::factory()->published()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id, ['crop_id' => $otherCrop->id, 'crop_variety_id' => null, 'crop_stage_id' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('crop_id');

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id, 'crop_stage_id' => $context['stage']->id]);
});

test('retained inactive districts survive unrelated draft edits but cannot be newly added', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    $district->update(['is_active' => false]);
    $newInactive = activityTemplateDistrict('Ajmer', $district->parent);
    $newInactive->update(['is_active' => false]);
    $path = '/api/admin/crop-activity-templates/'.$template->id;

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson($path, ['priority' => 'low'])->assertOk()->assertJsonPath('data.region_ids', [$district->id]);
    $this->patchJson($path, ['region_ids' => [$district->id, $newInactive->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('region_ids.1');

    $this->assertDatabaseCount('crop_activity_template_regions', 1);
    $this->assertDatabaseHas('crop_activity_template_regions', ['crop_activity_template_id' => $template->id, 'region_id' => $district->id]);
});

test('only the established reviewer permission can publish templates or edit active recommendations', function () {
    $admin = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    $path = '/api/admin/crop-activity-templates/'.$template->id;
    $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
    $reviewerToken = $reviewer->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($adminToken)->patchJson($path.'/status', ['is_active' => true])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewerToken)->patchJson($path.'/status', ['is_active' => true])->assertOk()
        ->assertJsonPath('data.is_active', true)->assertJsonPath('data.publication_warnings', []);
    app('auth')->forgetGuards();
    $this->withToken($adminToken)->patchJson($path, ['priority' => 'high'])->assertForbidden();
    app('auth')->forgetGuards();
    $this->withToken($reviewerToken)->patchJson($path, ['priority' => 'high'])->assertOk()->assertJsonPath('data.is_active', true);
    app('auth')->forgetGuards();
    $this->withToken($adminToken)->patchJson($path.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'priority' => 'high', 'is_active' => false]);
    $this->assertDatabaseCount('crop_activity_template_translations', 2);
});

test('an explicit reviewer gate denial protects publication even for super users', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    Gate::define('review-knowledge-sources', fn (Authenticatable $user): bool => false);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', ['is_active' => true])->assertForbidden();

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
});

test('template publication rejects inactive related catalogue records', function (string $relation, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    $context[$relation]->forceFill(['is_active' => false])->save();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
})->with([
    'crop' => ['crop', 'crop_id'], 'variety' => ['variety', 'crop_variety_id'],
    'category' => ['category', 'category_id'], 'stage' => ['stage', 'crop_stage_id'],
    'season' => ['season', 'season_id'], 'source' => ['source', 'source_id'],
]);

test('template publication requires verified sources with a usable reference', function (string $status, bool $usableReference) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    $context['source']->forceFill([
        'verification_status' => $status, 'source_url' => $usableReference ? 'https://example.org/reference' : null,
        'reference_identifier' => null,
    ])->save();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('source_id');

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
})->with([
    'pending source' => ['pending', true], 'rejected source' => ['rejected', true],
    'outdated source' => ['outdated', true], 'no usable reference' => ['verified', false],
]);

test('template publication rejects missing required titles and instructions and unsafe stored scheduling', function (string $invalidState, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    if ($invalidState === 'missing-title') {
        $template->translations()->where('locale', 'hi')->delete();
    } elseif ($invalidState === 'missing-instructions') {
        $template->translations()->where('locale', 'en')->update(['instructions' => null]);
    } elseif ($invalidState === 'invalid-schedule') {
        $template->update(['schedule_type' => 'stage_start']);
    } elseif ($invalidState === 'missing-offset') {
        $template->update(['end_offset_days' => null]);
    } elseif ($invalidState === 'reversed-window') {
        $template->update(['start_offset_days' => 20, 'end_offset_days' => 10]);
    } else {
        $context['category']->update(['slug' => 'ask-agricultural-expert']);
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', ['is_active' => true])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
})->with([
    'Hindi title' => ['missing-title', 'translations.hi.title'],
    'English instructions' => ['missing-instructions', 'translations.en.instructions'],
    'unsupported stored anchor' => ['invalid-schedule', 'schedule_type'],
    'missing stored offset' => ['missing-offset', 'end_offset_days'],
    'reversed stored window' => ['reversed-window', 'end_offset_days'],
    'unreleased category' => ['unreleased-category', 'category_id'],
]);

test('each published supported translation needs its own farmer facing instructions', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);
    $template->translations()->create(['locale' => 'gu', 'title' => 'ઉદાહરણ કાર્ય']);
    $path = '/api/admin/crop-activity-templates/'.$template->id;
    $token = $admin->createToken('test', ['admin'])->plainTextToken;

    $this->withToken($token)->patchJson($path.'/status', ['is_active' => true])->assertUnprocessable()
        ->assertJsonValidationErrors('translations.gu.instructions');
    $this->patchJson($path, ['translations' => ['gu' => ['instructions' => 'પરીક્ષણ માટે સૂચનાઓ.']]])->assertOk();
    $this->patchJson($path.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);

    $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'gu', 'instructions' => 'પરીક્ષણ માટે સૂચનાઓ.']);
    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => true]);
});

test('active template edits recheck source eligibility and preserve approved content after rejection', function (string $change) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context, ['is_active' => true]);
    $payload = $change === 'instructions' ? ['translations' => ['hi' => ['instructions' => null]]] : ['priority' => 'high'];
    if ($change === 'source') {
        $context['source']->forceFill(['verification_status' => 'pending'])->save();
    }

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id, $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($change === 'source' ? 'source_id' : 'translations.hi.instructions');

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'priority' => 'medium', 'is_active' => true]);
    $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'hi', 'instructions' => 'परीक्षण के उदाहरण निर्देश।']);
})->with(['source', 'instructions']);

test('publication validates districts while deactivation preserves selections even after districts become inactive', function () {
    $admin = AdminUser::factory()->create();
    $reviewer = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    $district->update(['is_active' => false]);
    $path = '/api/admin/crop-activity-templates/'.$template->id.'/status';

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson($path, ['is_active' => false])->assertOk()->assertJsonPath('data.region_ids', [$district->id]);
    app('auth')->forgetGuards();
    $this->withToken($reviewer->createToken('test', ['admin'])->plainTextToken)
        ->patchJson($path, ['is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('region_ids.0');

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
    $this->assertDatabaseHas('crop_activity_template_regions', ['crop_activity_template_id' => $template->id, 'region_id' => $district->id]);
});

test('status changes reject protected metadata and invalid boolean payloads', function (array $payload, string $field) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson('/api/admin/crop-activity-templates/'.$template->id.'/status', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => false]);
})->with([
    'required flag' => [[], 'is_active'], 'invalid flag' => [['is_active' => 'yes'], 'is_active'],
    'metadata injection' => [['is_active' => true, 'priority' => 'high'], 'priority'],
    'translation injection' => [['is_active' => true, 'translations' => ['en' => ['title' => 'Changed']]], 'translations'],
]);

test('legitimate scope intersections warn on activation and active edits without blocking recommendations', function () {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $existing = activityTemplateRecord($context, ['is_active' => true, 'crop_variety_id' => null, 'crop_stage_id' => null]);
    $template = activityTemplateRecord($context);
    $path = '/api/admin/crop-activity-templates/'.$template->id;

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->patchJson($path.'/status', ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.publication_warnings.0.code', 'potential_overlap')
        ->assertJsonPath('data.publication_warnings.0.template_ids', [$existing->id]);
    $this->patchJson($path, ['priority' => 'high'])->assertOk()->assertJsonPath('data.publication_warnings.0.template_ids', [$existing->id]);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'is_active' => true, 'priority' => 'high']);
    $this->assertDatabaseHas('crop_activity_templates', ['id' => $existing->id, 'is_active' => true]);
});

test('potential template overlap checks honor every scope and scheduling dimension', function (string $scenario, bool $overlaps) {
    $context = activityTemplateContext(published: true);
    $existing = activityTemplateRecord($context, ['is_active' => true, 'start_offset_days' => 10, 'end_offset_days' => 20]);
    $candidate = activityTemplateRecord($context, ['start_offset_days' => 10, 'end_offset_days' => 20]);
    $district = activityTemplateDistrict();
    $otherDistrict = activityTemplateDistrict('Ajmer', $district->parent);
    $regionIds = [];
    switch ($scenario) {
        case 'inactive':
            $existing->update(['is_active' => false]);
            break;
        case 'different-crop':
            $candidate->crop_id = Crop::factory()->published()->create()->id;
            $candidate->crop_variety_id = null;
            $candidate->crop_stage_id = null;
            break;
        case 'different-category':
            $candidate->category_id = ActivityCategory::factory()->published()->create()->id;
            break;
        case 'different-season':
            $candidate->season_id = Season::factory()->withNames()->create(['is_active' => true])->id;
            break;
        case 'different-variety':
            $candidate->crop_variety_id = CropVariety::factory()->for($context['crop'])->withNames()->create(['is_active' => true])->id;
            break;
        case 'different-stage':
            $candidate->crop_stage_id = CropStage::factory()->for($context['crop'])->withNames()->create(['is_active' => true, 'sequence' => 2])->id;
            break;
        case 'generic-variety':
            $candidate->crop_variety_id = null;
            break;
        case 'generic-stage':
            $candidate->crop_stage_id = null;
            break;
        case 'earlier-window':
            $candidate->start_offset_days = 0;
            $candidate->end_offset_days = 9;
            break;
        case 'later-window':
            $candidate->start_offset_days = 21;
            $candidate->end_offset_days = 30;
            break;
        case 'touching-window':
            $candidate->start_offset_days = 20;
            $candidate->end_offset_days = 30;
            break;
        case 'all-with-specific':
            $existing->update(['region_scope' => 'specific']);
            $existing->regions()->attach($district);
            break;
        case 'specific-with-all':
            $candidate->region_scope = 'specific';
            $regionIds = [$district->id];
            break;
        case 'intersecting-districts':
        case 'disjoint-districts':
            $existing->update(['region_scope' => 'specific']);
            $existing->regions()->attach($district);
            $candidate->region_scope = 'specific';
            $regionIds = [$scenario === 'intersecting-districts' ? $district->id : $otherDistrict->id];
            break;
    }

    expect($candidate->potentialOverlapIds($regionIds))->toBe($overlaps ? [$existing->id] : []);
})->with([
    'same applicability' => ['same', true], 'inactive record' => ['inactive', false],
    'different crop' => ['different-crop', false], 'different category' => ['different-category', false],
    'different season' => ['different-season', false], 'different variety' => ['different-variety', false],
    'different stage' => ['different-stage', false], 'general variety' => ['generic-variety', true],
    'general stage' => ['generic-stage', true], 'earlier window' => ['earlier-window', false],
    'later window' => ['later-window', false], 'touching window' => ['touching-window', true],
    'all includes specific' => ['all-with-specific', true], 'specific intersects all' => ['specific-with-all', true],
    'intersecting districts' => ['intersecting-districts', true], 'disjoint districts' => ['disjoint-districts', false],
]);

test('template preview resolves every catalogue label and translated content using locale headers', function (array $headers, array $expected) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $context['crop']->translations()->create(['locale' => 'gu', 'name' => 'ઘઉં']);
    $context['variety']->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ જાત']);
    $context['category']->translations()->create(['locale' => 'gu', 'name' => 'પાક નિરીક્ષણ']);
    $context['stage']->translations()->create(['locale' => 'gu', 'name' => 'ઉદાહરણ અવસ્થા']);
    $context['season']->translations()->create(['locale' => 'gu', 'name' => 'રબી']);
    $context['source']->translations()->createMany([
        ['locale' => 'en', 'title' => 'English reference'], ['locale' => 'hi', 'title' => 'हिन्दी संदर्भ'], ['locale' => 'gu', 'title' => 'ગુજરાતી સંદર્ભ'],
    ]);
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $template->translations()->create(['locale' => 'gu', 'title' => 'ખેતર તપાસો', 'description' => 'ગુજરાતી વર્ણન', 'instructions' => 'ગુજરાતી સૂચનાઓ']);
    $district = activityTemplateDistrict();
    $district->translations()->create(['locale' => 'hi', 'name' => 'जयपुर']);
    $district->translations()->create(['locale' => 'gu', 'name' => 'જયપુર']);
    $template->regions()->attach($district);

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/crop-activity-templates/'.$template->id.'?locale=en')->assertOk()
        ->assertJsonCount(3, 'data.translations')->assertJsonPath('data.region_ids', [$district->id]);
    foreach ($expected as $field => $value) {
        $response->assertJsonPath('data.'.$field, $value);
    }
})->with([
    'English default' => [[], ['title' => 'Inspect field conditions', 'crop_name' => 'Wheat', 'crop_variety_name' => 'Example variety', 'category_name' => 'Crop monitoring', 'crop_stage_name' => 'Example stage', 'season_name' => 'Rabi', 'source_title' => 'English reference', 'regions.0.name' => 'Jaipur']],
    'Hindi locale header' => [['X-Locale' => 'hi'], ['title' => 'खेत की स्थिति की जाँच करें', 'crop_name' => 'गेहूँ', 'crop_variety_name' => 'उदाहरण किस्म', 'category_name' => 'फसल निगरानी', 'crop_stage_name' => 'उदाहरण अवस्था', 'season_name' => 'रबी', 'source_title' => 'हिन्दी संदर्भ', 'regions.0.name' => 'जयपुर']],
    'Hindi language header' => [['Accept-Language' => 'hi'], ['title' => 'खेत की स्थिति की जाँच करें', 'category_name' => 'फसल निगरानी', 'source_title' => 'हिन्दी संदर्भ']],
    'configured Gujarati' => [['X-Locale' => 'gu'], ['title' => 'ખેતર તપાસો', 'description' => 'ગુજરાતી વર્ણન', 'instructions' => 'ગુજરાતી સૂચનાઓ', 'crop_name' => 'ઘઉં', 'crop_variety_name' => 'ઉદાહરણ જાત', 'category_name' => 'પાક નિરીક્ષણ', 'crop_stage_name' => 'ઉદાહરણ અવસ્થા', 'season_name' => 'રબી', 'source_title' => 'ગુજરાતી સંદર્ભ', 'regions.0.name' => 'જયપુર']],
    'locale header precedence' => [['X-Locale' => 'en', 'Accept-Language' => 'hi'], ['title' => 'Inspect field conditions', 'source_title' => 'English reference']],
]);

test('template previews use English fallback and never expose knowledge source attachments', function () {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    KnowledgeSourceAttachment::factory()->for($context['source'])->create(['file_path' => 'private/source-evidence.pdf']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'gu')
        ->getJson('/api/admin/crop-activity-templates/'.$template->id)->assertOk()
        ->assertJsonPath('data.title', 'Inspect field conditions')->assertJsonPath('data.crop_name', 'Wheat')
        ->assertJsonPath('data.source_title', 'Illustrative test publication')
        ->assertJsonPath('data.source.reference_identifier', $context['source']->reference_identifier)
        ->assertJsonMissingPath('data.source.attachments')->assertJsonMissingPath('data.source.file_path')
        ->assertDontSee('private/source-evidence.pdf', false);
});

test('template listings search configured titles crop names and category names without duplicated rows', function (string $keyword, int $count) {
    config()->set('languages.supported.gu', 'Gujarati');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    $template->translations()->create(['locale' => 'gu', 'title' => 'ખેતર તપાસો 50%_!']);
    $template->translations()->create(['locale' => 'fr', 'title' => 'Unsupported stored title']);
    CropActivityTemplate::factory()->for(Crop::factory()->published('Other crop', 'अन्य फसल'), 'crop')
        ->withTitles('Unrelated task', 'अन्य कार्य')->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates?'.http_build_query(['keyword' => $keyword]))
        ->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('pagination.total', $count);
})->with([
    'English title' => ['Inspect field conditions', 1], 'Hindi title' => ['खेत की स्थिति', 1],
    'Gujarati title' => ['ખેતર તપાસો', 1], 'English crop' => ['Wheat', 1], 'Hindi crop' => ['गेहूँ', 1],
    'English category' => ['Crop monitoring', 1], 'Hindi category' => ['फसल निगरानी', 1],
    'literal wildcards' => ['50%_!', 1], 'escaped wildcards' => ['50%_x', 0],
    'unsupported stored locale' => ['Unsupported stored title', 0], 'SQL punctuation' => ["' OR 1=1 --", 0],
]);

test('template list filters isolate each related record priority and status', function (string $filter) {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $target = activityTemplateRecord($context, ['priority' => 'high', 'is_active' => true]);
    CropActivityTemplate::factory()->withTitles('Other activity')->create();
    $filters = [
        'crop_id' => $context['crop']->id, 'crop_variety_id' => $context['variety']->id,
        'category_id' => $context['category']->id, 'crop_stage_id' => $context['stage']->id,
        'season_id' => $context['season']->id, 'priority' => 'high', 'status' => 1,
    ];

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates?'.http_build_query([$filter => $filters[$filter]]))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id)->assertJsonPath('pagination.total', 1);
})->with(['crop_id', 'crop_variety_id', 'category_id', 'crop_stage_id', 'season_id', 'priority', 'status']);

test('template list pagination uses newest then ID ordering and keeps only allowed filters', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $older = activityTemplateRecord($context, ['created_at' => '2026-11-01 09:00:00']);
    $firstSameTime = activityTemplateRecord($context, ['created_at' => '2026-11-06 09:00:00']);
    $newest = activityTemplateRecord($context, ['created_at' => '2026-11-06 09:00:00']);
    $query = ['crop_id' => $context['crop']->id, 'category_id' => $context['category']->id, 'status' => 0, 'priority' => 'medium', 'keyword' => 'Inspect', 'per_page' => 1];

    $response = $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->getJson('/api/admin/crop-activity-templates?'.http_build_query([...$query, 'locale' => 'hi', 'ignored' => 1]))
        ->assertOk()->assertJsonPath('data.0.id', $newest->id)->assertJsonPath('data.0.title', 'खेत की स्थिति की जाँच करें')
        ->assertJsonPath('data.0.crop_name', 'गेहूँ')->assertJsonPath('data.0.category_name', 'फसल निगरानी')
        ->assertJsonMissingPath('data.0.translations')->assertJsonMissingPath('data.0.region_ids')
        ->assertJsonMissingPath('data.0.regions')->assertJsonMissingPath('data.0.instructions')
        ->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.current_page', 1)->assertJsonPath('pagination.last_page', 3);

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(array_keys($response->json('pagination')))->toBe(['current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'next_page_url', 'prev_page_url']);
    expect($response->json('pagination.next_page_url'))->toContain('crop_id='.$context['crop']->id, 'keyword=Inspect', 'status=0', 'priority=medium', 'per_page=1')
        ->not->toContain('locale=', 'ignored=');
    $this->getJson($response->json('pagination.next_page_url'))->assertOk()->assertJsonPath('data.0.id', $firstSameTime->id);
    $this->getJson('/api/admin/crop-activity-templates?'.http_build_query([...$query, 'page' => 3]))->assertOk()->assertJsonPath('data.0.id', $older->id);
});

test('empty template pages and default page size follow the length aware pagination contract', function () {
    $admin = AdminUser::factory()->create();
    CropActivityTemplate::factory()->withTitles()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates')->assertOk()->assertJsonPath('pagination.per_page', 15);
    $this->getJson('/api/admin/crop-activity-templates?page=2&per_page=1')->assertOk()->assertJsonPath('data', [])
        ->assertJsonPath('pagination.total', 1)->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid template list filters return 422', function (array $filters, string $field) {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->getJson('/api/admin/crop-activity-templates?'.http_build_query($filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'page zero' => [['page' => 0], 'page'], 'per page zero' => [['per_page' => 0], 'per_page'],
    'page maximum' => [['per_page' => 101], 'per_page'], 'invalid priority' => [['priority' => 'urgent'], 'priority'],
    'invalid status' => [['status' => 'yes'], 'status'], 'long keyword' => [['keyword' => str_repeat('a', 151)], 'keyword'],
    'unknown crop' => [['crop_id' => 99999], 'crop_id'], 'unknown variety' => [['crop_variety_id' => 99999], 'crop_variety_id'],
    'unknown category' => [['category_id' => 99999], 'category_id'], 'unknown stage' => [['crop_stage_id' => 99999], 'crop_stage_id'],
    'unknown season' => [['season_id' => 99999], 'season_id'],
]);

test('template list resources load each related catalogue once and never fetch region trees or full template translations', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    activityTemplateRecord($context);
    activityTemplateRecord($context);
    activityTemplateRecord($context);
    $token = $admin->createToken('test', ['admin'])->plainTextToken;
    DB::enableQueryLog();

    try {
        $this->withToken($token)->getJson('/api/admin/crop-activity-templates')->assertOk()->assertJsonCount(3, 'data');

        foreach (['crops', 'crop_varieties', 'activity_categories', 'crop_stages', 'seasons', 'knowledge_sources', 'crop_translations', 'crop_variety_translations', 'activity_category_translations', 'crop_stage_translations', 'season_translations', 'knowledge_source_translations'] as $table) {
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"'));
            expect($queries)->toHaveCount(1);
        }
        foreach (['regions', 'crop_activity_template_translations'] as $table) {
            $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "'.$table.'"'));
            expect($queries)->toHaveCount(0);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('template resources do not query unloaded relationships', function () {
    $template = CropActivityTemplate::factory()->make(['crop_id' => 1, 'category_id' => 1, 'season_id' => 1, 'source_id' => 1]);
    DB::enableQueryLog();

    try {
        $data = (new CropActivityTemplateResource($template))->resolve(Request::create('/'));

        expect($data)->not->toHaveKeys(['crop_name', 'category_name', 'source_title', 'translations', 'regions', 'instructions']);
        expect($data['title'])->toBeNull();
        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('unused inactive templates delete only their own translations and region associations', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $other = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    $other->regions()->attach($district);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-activity-templates/'.$template->id)->assertOk()->assertExactJson([
            'status' => true, 'data' => null, 'pagination' => null, 'message' => 'Crop activity template deleted successfully.',
        ]);

    $this->assertModelMissing($template);
    $this->assertModelExists($other);
    $this->assertModelExists($district);
    foreach ($context as $model) {
        $this->assertModelExists($model);
    }
    $this->assertDatabaseCount('crop_activity_template_translations', 2);
    $this->assertDatabaseCount('crop_activity_template_regions', 1);
    $this->assertDatabaseMissing('crop_activity_template_translations', ['crop_activity_template_id' => $template->id]);
    $this->assertDatabaseMissing('crop_activity_template_regions', ['crop_activity_template_id' => $template->id]);
});

test('active templates return 409 on deletion and keep their content', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context, ['is_active' => true]);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->deleteJson('/api/admin/crop-activity-templates/'.$template->id)->assertConflict()->assertJsonPath('status', false)
        ->assertJsonPath('data', null)->assertJsonPath('pagination', null);

    $this->assertModelExists($template);
    $this->assertDatabaseCount('crop_activity_template_translations', 2);
});

test('template edits and deactivation preserve historical activity snapshots and protected references', function (string $deleteAction) {
    $admin = AdminUser::factory()->superUser()->create();
    $context = activityTemplateContext(published: true);
    $template = activityTemplateRecord($context, ['region_scope' => 'specific', 'is_active' => true]);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    Schema::create('activity_template_history', function (Blueprint $table) use ($deleteAction): void {
        $table->id();
        $table->unsignedBigInteger('template_id')->nullable();
        $table->date('scheduled_on');
        $table->text('instructions_snapshot');
        $table->foreign('template_id')->references('id')->on('crop_activity_templates')->onDelete($deleteAction);
    });
    DB::table('activity_template_history')->insert(['template_id' => $template->id, 'scheduled_on' => '2026-11-20', 'instructions_snapshot' => 'Original farmer activity snapshot.']);
    $path = '/api/admin/crop-activity-templates/'.$template->id;

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson($path, ['translations' => ['en' => ['instructions' => 'Revised example instructions.']]])->assertOk();
        $this->patchJson($path.'/status', ['is_active' => false])->assertOk()->assertJsonPath('data.region_ids', [$district->id]);
        $this->deleteJson($path)->assertConflict();
        $this->getJson($path)->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('activity_template_history', ['template_id' => $template->id, 'scheduled_on' => '2026-11-20', 'instructions_snapshot' => 'Original farmer activity snapshot.']);
        $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'en', 'instructions' => 'Revised example instructions.']);
        $this->assertDatabaseHas('crop_activity_template_regions', ['crop_activity_template_id' => $template->id, 'region_id' => $district->id]);
    } finally {
        Schema::dropIfExists('activity_template_history');
    }
})->with(['restrict' => 'restrict', 'cascade' => 'cascade', 'set null' => 'set null']);

test('template foreign keys protect all referenced master records', function (string $relation) {
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);
    $record = $relation === 'district' ? $district : $context[$relation];

    expect(fn () => DB::table($record->getTable())->where('id', $record->id)->delete())->toThrow(QueryException::class);

    $this->assertModelExists($record);
    $this->assertModelExists($template);
})->with(['crop', 'variety', 'category', 'stage', 'season', 'source', 'district']);

test('template translation and district association uniqueness is enforced by the database', function (string $association) {
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $district = activityTemplateDistrict();
    $template->regions()->attach($district);

    expect(fn () => $association === 'translation'
        ? $template->translations()->create(['locale' => 'en', 'title' => 'Duplicate'])
        : $template->regions()->attach($district))->toThrow(UniqueConstraintViolationException::class);

    $this->assertDatabaseCount('crop_activity_template_translations', 2);
    $this->assertDatabaseCount('crop_activity_template_regions', 1);
})->with(['translation', 'district']);

test('failed template translation insertion rolls back the master earlier translations and regions', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $district = activityTemplateDistrict();
    DB::unprepared("CREATE TRIGGER fail_template_translation_insert BEFORE INSERT ON crop_activity_template_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, ['region_scope' => 'specific', 'region_ids' => [$district->id]]))
            ->assertInternalServerError()->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unable to create the crop activity template. Please try again later.',
            ]);

        $this->assertDatabaseCount('crop_activity_templates', 0);
        $this->assertDatabaseCount('crop_activity_template_translations', 0);
        $this->assertDatabaseCount('crop_activity_template_regions', 0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_template_translation_insert');
    }
});

test('failed district insertion rolls back new templates and their translations', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $first = activityTemplateDistrict();
    $second = activityTemplateDistrict('Ajmer', $first->parent);
    DB::unprepared('CREATE TRIGGER fail_template_region_insert BEFORE INSERT ON crop_activity_template_regions FOR EACH ROW WHEN NEW.region_id = '.$second->id." BEGIN SELECT RAISE(ABORT, 'Simulated district failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, ['region_scope' => 'specific', 'region_ids' => [$first->id, $second->id]]))
            ->assertInternalServerError();

        $this->assertDatabaseCount('crop_activity_templates', 0);
        $this->assertDatabaseCount('crop_activity_template_translations', 0);
        $this->assertDatabaseCount('crop_activity_template_regions', 0);
    } finally {
        DB::unprepared('DROP TRIGGER fail_template_region_insert');
    }
});

test('failed translation updates restore preceding content and metadata changes', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    DB::unprepared("CREATE TRIGGER fail_template_translation_update BEFORE UPDATE ON crop_activity_template_translations FOR EACH ROW WHEN NEW.locale = 'hi' BEGIN SELECT RAISE(ABORT, 'Simulated translation failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson('/api/admin/crop-activity-templates/'.$template->id, [
                'priority' => 'high', 'end_offset_days' => 20, 'translations' => ['en' => ['title' => 'Changed English'], 'hi' => ['title' => 'Changed Hindi']],
            ])->assertInternalServerError();

        $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'priority' => 'medium', 'end_offset_days' => 10]);
        $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'en', 'title' => 'Inspect field conditions']);
        $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'hi', 'title' => 'खेत की स्थिति की जाँच करें']);
    } finally {
        DB::unprepared('DROP TRIGGER fail_template_translation_update');
    }
});

test('failed district replacement restores detached selections translations and metadata', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context, ['region_scope' => 'specific']);
    $original = activityTemplateDistrict();
    $second = activityTemplateDistrict('Ajmer', $original->parent);
    $third = activityTemplateDistrict('Bhilwara', $original->parent);
    $template->regions()->attach($original);
    DB::unprepared('CREATE TRIGGER fail_template_region_update BEFORE INSERT ON crop_activity_template_regions FOR EACH ROW WHEN NEW.region_id = '.$third->id." BEGIN SELECT RAISE(ABORT, 'Simulated district failure.'); END");

    try {
        $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
            ->patchJson('/api/admin/crop-activity-templates/'.$template->id, [
                'priority' => 'high', 'translations' => ['en' => ['title' => 'Changed English']], 'region_ids' => [$second->id, $third->id],
            ])->assertInternalServerError();

        $this->assertDatabaseHas('crop_activity_templates', ['id' => $template->id, 'priority' => 'medium']);
        $this->assertDatabaseHas('crop_activity_template_translations', ['crop_activity_template_id' => $template->id, 'locale' => 'en', 'title' => 'Inspect field conditions']);
        $this->assertDatabaseCount('crop_activity_template_regions', 1);
        $this->assertDatabaseHas('crop_activity_template_regions', ['crop_activity_template_id' => $template->id, 'region_id' => $original->id]);
    } finally {
        DB::unprepared('DROP TRIGGER fail_template_region_update');
    }
});

test('the signed INTEGER boundary is accepted without imposing an agricultural day maximum', function () {
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crop-activity-templates', activityTemplatePayload($context, ['start_offset_days' => 2147483647, 'end_offset_days' => 2147483647]))
        ->assertCreated()->assertJsonPath('data.start_offset_days', 2147483647)->assertJsonPath('data.end_offset_days', 2147483647);

    $this->assertDatabaseHas('crop_activity_templates', ['start_offset_days' => 2147483647, 'end_offset_days' => 2147483647]);
});

test('missing template routes return the shared JSON 404 without an Accept header', function () {
    $admin = AdminUser::factory()->create();

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)
        ->get('/api/admin/crop-activity-templates/99999')->assertNotFound()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
        ]);
});

test('template listing titles follow locale headers with English fallback', function (array $headers, string $expectedTitle) {
    config()->set('languages.supported.gu', 'Gujarati');
    config()->set('languages.supported.mr', 'Marathi');
    $admin = AdminUser::factory()->create();
    $context = activityTemplateContext();
    $template = activityTemplateRecord($context);
    $template->translations()->create(['locale' => 'gu', 'title' => 'ખેતર તપાસો']);

    $this->withToken($admin->createToken('test', ['admin'])->plainTextToken)->withHeaders($headers)
        ->getJson('/api/admin/crop-activity-templates?locale=en')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $template->id)->assertJsonPath('data.0.title', $expectedTitle)
        ->assertJsonPath('pagination.total', 1);
})->with([
    'English default' => [[], 'Inspect field conditions'],
    'Hindi locale header' => [['X-Locale' => 'hi'], 'खेत की स्थिति की जाँच करें'],
    'Hindi language header' => [['Accept-Language' => 'hi'], 'खेत की स्थिति की जाँच करें'],
    'configured Gujarati' => [['X-Locale' => 'gu'], 'ખેતર તપાસો'],
    'missing Marathi fallback' => [['X-Locale' => 'mr'], 'Inspect field conditions'],
    'unsupported locale fallback' => [['X-Locale' => 'fr', 'Accept-Language' => 'hi'], 'Inspect field conditions'],
    'locale header precedence' => [['X-Locale' => 'en', 'Accept-Language' => 'hi'], 'Inspect field conditions'],
]);

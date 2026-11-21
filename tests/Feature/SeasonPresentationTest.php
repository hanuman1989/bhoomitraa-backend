<?php

use App\Http\Controllers\Admin\SeasonController;
use App\Http\Requests\Admin\UpdateSeasonRequest;
use App\Models\AdminUser;
use App\Models\CropActivityTemplate;
use App\Models\CropSeedRate;
use App\Models\CropStageTimeline;
use App\Models\CropYieldBenchmark;
use App\Models\FertilizerRecommendation;
use App\Models\Season;
use App\Models\User;
use Database\Seeders\SeasonSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once __DIR__.'/../FarmCropFixtures.php';

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function seasonPresentationPayload(array $overrides = []): array
{
    return array_replace(['slug' => 'custom-season', 'translations' => [
        'en' => ['name' => 'Custom season', 'description' => 'Display metadata only.'],
        'hi' => ['name' => 'कृषि मौसम', 'description' => null],
    ]], $overrides);
}

function seasonPresentationAdmin(TestCase $test): void
{
    $admin = AdminUser::factory()->create();
    $test->withToken($admin->createToken('season-presentation', ['admin'])->plainTextToken);
}

test('creates presentation metadata for cross-year equal-month and ordinary periods with each supported icon', function (int $start, int $end, string $icon) {
    seasonPresentationAdmin($this);

    $this->postJson('/api/admin/seasons', seasonPresentationPayload(['start_month' => $start, 'end_month' => $end, 'icon' => $icon]))
        ->assertCreated()->assertJsonPath('data.start_month', $start)->assertJsonPath('data.end_month', $end)
        ->assertJsonPath('data.icon', $icon)->assertJsonPath('data.is_active', false);
    $this->assertDatabaseHas('seasons', ['slug' => 'custom-season', 'start_month' => $start, 'end_month' => $end, 'icon' => $icon]);
    $this->assertDatabaseCount('season_translations', 2);
})->with(['cross-year sprout' => [10, 3, 'sprout'], 'same month sun' => [5, 5, 'sun'], 'full year leaf' => [1, 12, 'leaf']]);

test('returns null optional fields when metadata is omitted or explicitly cleared on creation', function (array $metadata) {
    seasonPresentationAdmin($this);

    $this->postJson('/api/admin/seasons', seasonPresentationPayload($metadata))->assertCreated()
        ->assertJsonPath('data.start_month', null)->assertJsonPath('data.end_month', null)->assertJsonPath('data.icon', null);
    $this->assertDatabaseHas('seasons', ['slug' => 'custom-season', 'start_month' => null, 'end_month' => null, 'icon' => null]);
})->with(['omitted' => [[]], 'explicit null' => [['start_month' => null, 'end_month' => null, 'icon' => null]]]);

test('returns 422 for invalid months icons or incomplete creation pairs without saving records', function (array $metadata, string $field) {
    seasonPresentationAdmin($this);

    $response = $this->postJson('/api/admin/seasons', seasonPresentationPayload($metadata))->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('seasons', 0);
    $this->assertDatabaseCount('season_translations', 0);
})->with([
    'below range' => [['start_month' => 0, 'end_month' => 3], 'start_month'],
    'above range' => [['start_month' => 10, 'end_month' => 13], 'end_month'],
    'negative month' => [['start_month' => -1, 'end_month' => 3], 'start_month'],
    'fractional month' => [['start_month' => 10.5, 'end_month' => 3], 'start_month'],
    'month name' => [['start_month' => 'October', 'end_month' => 3], 'start_month'],
    'boolean month' => [['start_month' => true, 'end_month' => 3], 'start_month'],
    'array month' => [['start_month' => [10], 'end_month' => 3], 'start_month'],
    'start only' => [['start_month' => 10], 'end_month'],
    'end only' => [['end_month' => 3], 'start_month'],
    'one null' => [['start_month' => null, 'end_month' => 3], 'start_month'],
    'unsupported icon' => [['icon' => 'tractor'], 'icon'],
    'svg markup' => [['icon' => '<svg></svg>'], 'icon'],
    'html markup' => [['icon' => '<b>sun</b>'], 'icon'],
    'icon URL' => [['icon' => 'https://example.com/sun.svg'], 'icon'],
    'CSS class' => [['icon' => 'lucide-sprout'], 'icon'],
    'nonstring icon' => [['icon' => ['sun']], 'icon'],
    'oversized icon' => [['icon' => str_repeat('a', 31)], 'icon'],
]);

test('validates the effective stored pair for partial edits and clears both months only when requested', function () {
    seasonPresentationAdmin($this);
    $season = Season::factory()->withNames()->withPresentation()->create();

    $this->patchJson('/api/admin/seasons/'.$season->id, ['start_month' => 11])->assertOk()
        ->assertJsonPath('data.start_month', 11)->assertJsonPath('data.end_month', 3)->assertJsonPath('data.icon', 'sprout');
    $this->patchJson('/api/admin/seasons/'.$season->id, ['end_month' => 4])->assertOk()->assertJsonPath('data.start_month', 11)->assertJsonPath('data.end_month', 4);
    $this->patchJson('/api/admin/seasons/'.$season->id, ['start_month' => null])->assertUnprocessable()
        ->assertJsonValidationErrors(['start_month' => 'Both start month and end month must be provided together.', 'end_month']);
    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'start_month' => 11, 'end_month' => 4]);
    $this->patchJson('/api/admin/seasons/'.$season->id, ['start_month' => null, 'end_month' => null, 'icon' => null])->assertOk()
        ->assertJsonPath('data.start_month', null)->assertJsonPath('data.end_month', null)->assertJsonPath('data.icon', null);
    $this->patchJson('/api/admin/seasons/'.$season->id, ['end_month' => 4])->assertUnprocessable()->assertJsonValidationErrors('start_month');
});

test('preserves presentation metadata during translation updates and translations during metadata edits', function () {
    seasonPresentationAdmin($this);
    $season = Season::factory()->withNames()->withPresentation()->create();

    $this->patchJson('/api/admin/seasons/'.$season->id, ['translations' => ['en' => ['description' => 'Updated description']]])->assertOk()
        ->assertJsonPath('data.start_month', 10)->assertJsonPath('data.end_month', 3)->assertJsonPath('data.icon', 'sprout');
    $translations = $season->translations()->orderBy('id')->get()->toArray();
    $this->patchJson('/api/admin/seasons/'.$season->id, ['icon' => null])->assertOk()->assertJsonPath('data.icon', null)->assertJsonPath('data.start_month', 10);
    $this->patchJson('/api/admin/seasons/'.$season->id, ['start_month' => 6, 'end_month' => 10, 'icon' => 'leaf'])->assertOk();
    expect($season->translations()->orderBy('id')->get()->toArray())->toBe($translations);
});

test('accepts overlapping seasons and keeps icon choice independent of the season slug', function () {
    seasonPresentationAdmin($this);

    $this->postJson('/api/admin/seasons', seasonPresentationPayload(['slug' => 'rabi', 'start_month' => 10, 'end_month' => 3, 'icon' => 'sun']))->assertCreated();
    $this->postJson('/api/admin/seasons', seasonPresentationPayload(['slug' => 'different-season', 'start_month' => 10, 'end_month' => 3, 'icon' => 'sprout']))->assertCreated();

    $this->assertDatabaseCount('seasons', 2);
});

test('rechecks the effective pair against the locked record after form request validation', function () {
    seasonPresentationAdmin($this);
    $season = Season::factory()->withNames()->create();
    $route = app('router')->getRoutes()->getByName('admin.seasons.update');
    $action = $route->getAction();
    unset($action['controller']);
    $action['uses'] = function (UpdateSeasonRequest $request, Season $season): JsonResponse {
        DB::table('seasons')->where('id', $season->id)->update(['start_month' => 10, 'end_month' => 3]);

        return app(SeasonController::class)->update($request, $season);
    };
    $route->setAction($action);

    $this->patchJson('/api/admin/seasons/'.$season->id, ['start_month' => null])->assertUnprocessable()->assertJsonValidationErrors('end_month');

    $this->assertDatabaseHas('seasons', ['id' => $season->id, 'start_month' => 10, 'end_month' => 3]);
});

test('returns unchanged month integers and icon identifiers across admin and farmer locales', function () {
    seasonPresentationAdmin($this);
    $season = Season::factory()->withNames()->withPresentation()->create(['is_active' => true]);
    foreach (['en', 'hi'] as $locale) {
        $this->withHeader('X-Locale', $locale)->getJson('/api/admin/seasons/'.$season->id)->assertOk()
            ->assertJsonPath('data.start_month', 10)->assertJsonPath('data.end_month', 3)->assertJsonPath('data.icon', 'sprout');
        $this->getJson('/api/admin/seasons?status=1')->assertOk()->assertJsonPath('data.0.start_month', 10)->assertJsonPath('data.0.icon', 'sprout');
    }
    $farmer = User::factory()->create();
    app('auth')->forgetGuards();
    $this->withToken($farmer->createToken('season', ['frontend'])->plainTextToken);
    foreach (['en', 'hi'] as $locale) {
        $this->withHeader('X-Locale', $locale)->getJson('/api/account/seasons')->assertOk()
            ->assertJsonPath('data.0.start_month', 10)->assertJsonPath('data.0.end_month', 3)->assertJsonPath('data.0.icon', 'sprout');
    }
});

test('returns presentation metadata in every existing nested administrative season display', function (string $module) {
    seasonPresentationAdmin($this);
    $season = Season::factory()->withNames()->withPresentation()->create();
    $record = match ($module) {
        'crop-seed-rates' => CropSeedRate::factory()->create(['season_id' => $season->id]),
        'crop-yield-benchmarks' => CropYieldBenchmark::factory()->create(['season_id' => $season->id]),
        'fertilizer-recommendations' => FertilizerRecommendation::factory()->withNutrients()->create(['season_id' => $season->id]),
        'crop-stage-timelines' => (function () use ($season): CropStageTimeline {
            $timeline = CropStageTimeline::factory()->create(['season_scope' => 'specific']);
            $timeline->seasons()->attach($season);

            return $timeline;
        })(),
        'crop-activity-templates' => CropActivityTemplate::factory()->create(['season_id' => $season->id]),
    };

    foreach (['/api/admin/'.$module => 'data.0.season', '/api/admin/'.$module.'/'.$record->id => 'data.season'] as $url => $path) {
        $this->getJson($url)->assertOk()->assertJsonPath($path.'.start_month', 10)->assertJsonPath($path.'.end_month', 3)->assertJsonPath($path.'.icon', 'sprout');
    }
})->with(['crop-seed-rates', 'crop-yield-benchmarks', 'fertilizer-recommendations', 'crop-stage-timelines', 'crop-activity-templates']);

test('shows metadata on resumable crop resources and accepts farming dates outside the display period', function () {
    $context = cropManagerContext();
    $context['season']->update(['start_month' => 10, 'end_month' => 3, 'icon' => 'sprout']);
    $ready = cropManagerReady($this, $context);
    $id = $ready['id'];
    $this->getJson('/api/account/farm-crops')->assertOk()->assertJsonPath('data.0.season.start_month', 10);
    $this->getJson('/api/account/farm-crops/'.$id.'/review')->assertOk()->assertJsonPath('data.season.end_month', 3)->assertJsonPath('data.season.icon', 'sprout');

    $this->putJson('/api/account/farm-crops/'.$id.'/step-3', ['revision' => 5, 'seed_source' => 'farm_saved', 'sowing_date' => '2026-07-15',
        'quantity_mode' => 'unknown'])->assertOk()->assertJsonPath('data.sowing_date', '2026-07-15');
    $context['season']->update(['start_month' => 1, 'end_month' => 1, 'icon' => 'leaf']);
    $this->getJson('/api/account/farm-crops/'.$id)->assertOk()->assertJsonPath('data.season.icon', 'leaf')->assertJsonPath('data.can_finalize', true);
    $this->postJson('/api/account/farm-crops/'.$id.'/finalize', ['revision' => 6])->assertOk()->assertJsonPath('data.status', 'active');
});

test('returns season metadata in timeline previews without restricting sowing dates', function () {
    $context = cropManagerContext();
    $context['season']->update(['start_month' => 10, 'end_month' => 3, 'icon' => 'sprout']);
    seasonPresentationAdmin($this);

    $this->postJson('/api/admin/farmer-smart-timeline/preview', ['crop_id' => $context['crop']->id, 'season_id' => $context['season']->id,
        'district_id' => $context['district']->id, 'sowing_date' => '2026-07-15'])->assertOk()
        ->assertJsonPath('data.selection.season.start_month', 10)->assertJsonPath('data.selection.season.end_month', 3)
        ->assertJsonPath('data.selection.season.icon', 'sprout')->assertJsonPath('data.input.sowing_date', '2026-07-15');
});

test('seeds standard presentation defaults once and preserves customizations and intentional clearing on reruns', function () {
    $this->seed(SeasonSeeder::class);
    $rabi = Season::query()->where('slug', 'rabi')->sole();
    $this->assertDatabaseHas('seasons', ['id' => $rabi->id, 'start_month' => 10, 'end_month' => 3, 'icon' => 'sprout']);
    $this->assertDatabaseHas('seasons', ['slug' => 'kharif', 'start_month' => 6, 'end_month' => 10, 'icon' => 'sun']);
    $this->assertDatabaseHas('seasons', ['slug' => 'zaid', 'start_month' => 3, 'end_month' => 6, 'icon' => 'leaf']);
    $rabi->update(['start_month' => null, 'end_month' => null, 'icon' => null]);
    $kharif = Season::query()->where('slug', 'kharif')->sole();
    $kharif->update(['start_month' => 7, 'end_month' => 11, 'icon' => 'leaf']);
    $rabi->translations()->where('locale', 'en')->update(['name' => 'Custom winter label']);
    $before = Season::with('translations')->orderBy('id')->get()->toArray();

    $this->seed(SeasonSeeder::class);

    expect(Season::with('translations')->orderBy('id')->get()->toArray())->toBe($before);
    $this->assertDatabaseCount('seasons', 3);
    $this->assertDatabaseCount('season_translations', 6);
});

test('does not fill intentionally blank existing standard seasons during ordinary seeding', function () {
    $season = Season::factory()->withNames('Custom Rabi', 'विशेष रबी')->create(['slug' => 'rabi', 'is_active' => false]);
    $before = $season->fresh()->getRawOriginal();
    $translations = $season->translations()->get()->toArray();

    $this->seed(SeasonSeeder::class);

    expect($season->fresh()->getRawOriginal())->toBe($before);
    expect($season->translations()->get()->toArray())->toBe($translations);
});

test('keeps farmer season lookup queries bounded as the catalogue grows', function () {
    Season::factory()->withNames()->withPresentation()->create(['is_active' => true]);
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('season', ['frontend'])->plainTextToken);
    DB::enableQueryLog();
    try {
        DB::flushQueryLog();
        $this->getJson('/api/account/seasons')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        Season::factory()->count(5)->withNames()->withPresentation(6, 10, 'sun')->create(['is_active' => true]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/account/seasons')->assertOk()->assertJsonCount(6, 'data');
        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($count);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('does not allow presentation metadata inside translation objects', function () {
    seasonPresentationAdmin($this);
    $payload = seasonPresentationPayload();
    $payload['translations']['en']['start_month'] = 10;

    $this->postJson('/api/admin/seasons', $payload)->assertUnprocessable()->assertJsonValidationErrors('translations.en');
    $this->assertDatabaseCount('seasons', 0);
});

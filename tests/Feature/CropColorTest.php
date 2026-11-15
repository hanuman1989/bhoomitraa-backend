<?php

use App\Http\Resources\Admin\CropResource;
use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropCategory;
use App\Models\CropSeedRate;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\CropYieldBenchmark;
use App\Models\FertilizerRecommendation;
use App\Models\Region;
use App\Models\Season;
use App\Models\Seed;
use App\Models\SeedLot;
use App\Models\SeedPrice;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function cropColorPayload(CropCategory $category, array $attributes = []): array
{
    return ['category_id' => $category->id, 'slug' => 'mustard', 'translations' => [
        'en' => ['name' => 'Mustard'], 'hi' => ['name' => 'सरसों'],
    ], ...$attributes];
}

test('crop creation stores optional nullable and case preserving six digit colors', function (array $attributes, ?string $expected) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();

    $response = $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropColorPayload($category, $attributes))
        ->assertCreated()->assertJsonPath('data.crop_color', $expected)->assertJsonPath('pagination', null);

    $this->assertDatabaseHas('crops', ['id' => $response->json('data.id'), 'crop_color' => $expected]);
    $this->assertDatabaseCount('crop_translations', 2);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
})->with([
    'uppercase' => [['crop_color' => '#FFC107'], '#FFC107'],
    'lowercase' => [['crop_color' => '#ffffff'], '#ffffff'],
    'mixed case' => [['crop_color' => '#aBcD09'], '#aBcD09'],
    'black' => [['crop_color' => '#000000'], '#000000'],
    'omitted' => [[], null], 'explicit null' => [['crop_color' => null], null],
]);

test('invalid crop colors return 422 and never create a crop', function (mixed $color) {
    $admin = AdminUser::factory()->create();
    $category = CropCategory::factory()->create();

    $response = $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)
        ->postJson('/api/admin/crops', cropColorPayload($category, ['crop_color' => $color]))
        ->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonValidationErrors('crop_color');

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('crops', 0);
    $this->assertDatabaseCount('crop_translations', 0);
})->with([
    'missing hash' => ['FFC107'], 'short hex' => ['#ABC'], 'invalid digits' => ['#GGGGGG'],
    'alpha channel' => ['#12345678'], 'color name' => ['yellow'], 'RGB' => ['rgb(255, 193, 7)'],
    'RGBA' => ['rgba(255, 193, 7, 1)'], 'internal whitespace' => ['#FF C107'],
    'array' => [['#FFC107']], 'object' => [(object) ['value' => '#FFC107']],
    'integer' => [123456], 'boolean' => [true],
]);

test('crop color updates preserve omitted values clear null and leave translations untouched', function (string $method, array $attributes, ?string $expected) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withNames()->withColor('#FFC107')->create();
    $translations = $crop->translations()->orderBy('id')->get()->toArray();

    $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)
        ->json($method, '/api/admin/crops/'.$crop->id, $attributes)->assertOk()
        ->assertJsonPath('data.crop_color', $expected)->assertJsonPath('data.translations.en.name', 'Wheat');

    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'crop_color' => $expected]);
    expect($crop->translations()->orderBy('id')->get()->toArray())->toBe($translations);
})->with(['PATCH', 'PUT'])->with([
    'replace' => [['crop_color' => '#4caf50'], '#4caf50'], 'clear' => [['crop_color' => null], null],
    'preserve' => [['scientific_name' => 'Updated scientific name'], '#FFC107'],
]);

test('invalid color updates return localized 422 errors without changing the crop', function (string $locale, string $message) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->withColor('#FFC107')->create();
    $before = $crop->fresh()->getRawOriginal();

    $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->patchJson('/api/admin/crops/'.$crop->id, ['crop_color' => '#GGGGGG', 'scientific_name' => 'Must not save'])
        ->assertUnprocessable()->assertJsonPath('errors.crop_color.0', $message);

    expect($crop->fresh()->getRawOriginal())->toBe($before);
})->with([
    ['en', 'The crop color must be a valid six-digit hexadecimal color code, such as #FFC107.'],
    ['hi', 'फसल का रंग एक वैध छह अंकों वाला हेक्साडेसिमल रंग कोड होना चाहिए, जैसे #FFC107।'],
]);

test('crop search active listings and detail return the same master color in each locale', function (string $locale, ?string $color) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->create(['crop_color' => $color]);
    $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale);

    $this->getJson('/api/admin/crops?keyword=Wheat&is_active=1')->assertOk()
        ->assertJsonPath('data.0.id', $crop->id)->assertJsonPath('data.0.crop_color', $color);
    $this->getJson('/api/admin/crops/'.$crop->id)->assertOk()->assertJsonPath('data.crop_color', $color);
})->with(['en', 'hi'])->with(['#FFC107', null]);

test('published crop selections retain color without exposing translation collections or issuing resource queries', function () {
    $crop = Crop::factory()->published()->withColor('#FFC107')->create();
    $selected = Crop::query()->published()->findOrFail($crop->id);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $data = (new CropResource($selected))->resolve(Request::create('/catalogue'));

    expect($data['crop_color'])->toBe('#FFC107');
    expect($data)->not->toHaveKey('translations');
    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
});

test('crop color writes retain existing authentication and permission boundaries', function (string $method, string $identity, int $status) {
    $crop = Crop::factory()->withColor('#FFC107')->create();
    $before = $crop->fresh()->getRawOriginal();
    if ($identity === 'farmer') {
        $user = User::factory()->create();
        $this->withToken($user->createToken('farmer', ['*'])->plainTextToken);
    } elseif ($identity !== 'guest') {
        $admin = AdminUser::factory()->create(['status' => $identity !== 'inactive']);
        $this->withToken($admin->createToken('crop-color', $identity === 'wrong ability' ? ['frontend'] : ['admin'])->plainTextToken);
        if ($identity === 'denied') {
            Gate::define('manage-crops', fn (Authenticatable $user): bool => false);
        }
    }
    $path = '/api/admin/crops'.($method === 'PATCH' ? '/'.$crop->id : '');

    $this->json($method, $path, ['crop_color' => '#4CAF50'])->assertStatus($status)->assertJsonPath('status', false);

    expect($crop->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('crops', 1);
})->with(['POST', 'PATCH'])->with([
    ['guest', 401], ['farmer', 401], ['denied', 403], ['inactive', 403], ['wrong ability', 403],
]);

test('related crop displays load the shared color on lists and details without extra crop queries', function (Closure $factory, string $endpoint, array $fields) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->withColor('#FFC107')->create();
    $record = $factory($crop);
    $factory($crop);
    $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken);

    $detail = $this->getJson('/api/admin/'.$endpoint.'/'.$record->id)->assertOk();
    foreach ($fields as $field) {
        $detail->assertJsonPath('data.'.$field, '#FFC107');
    }
    $crop->update(['crop_color' => '#4CAF50']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $list = $this->getJson('/api/admin/'.$endpoint)->assertOk()->assertJsonCount(2, 'data');

    foreach ($fields as $field) {
        $list->assertJsonPath('data.0.'.$field, '#4CAF50')->assertJsonPath('data.1.'.$field, '#4CAF50');
    }
    $cropQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "crops"'));
    expect($cropQueries)->toHaveCount(1);
    DB::disableQueryLog();
})->with([
    'varieties' => [fn (Crop $crop): Model => CropVariety::factory()->for($crop)->withNames()->create(), 'crop-varieties', ['crop_color']],
    'stages' => [fn (Crop $crop): Model => CropStage::factory()->for($crop)->withNames()->create(['sequence' => $crop->stages()->count() + 1]), 'crop-stages', ['crop_color']],
    'stage timelines' => [fn (Crop $crop): Model => CropStageTimeline::factory()->for(CropStage::factory()->for($crop)->withNames()->create(['sequence' => $crop->stages()->count() + 1]), 'cropStage')->create(), 'crop-stage-timelines', ['crop_color']],
    'activity templates' => [fn (Crop $crop): Model => CropActivityTemplate::factory()->for($crop)->withTitles()->create(), 'crop-activity-templates', ['crop_color']],
    'seed rates' => [fn (Crop $crop): Model => CropSeedRate::factory()->for($crop)->create(), 'crop-seed-rates', ['crop_color', 'crop.crop_color']],
    'yield benchmarks' => [fn (Crop $crop): Model => CropYieldBenchmark::factory()->for($crop)->create(), 'crop-yield-benchmarks', ['crop_color', 'crop.crop_color']],
    'fertilizer recommendations' => [fn (Crop $crop): Model => FertilizerRecommendation::factory()->for($crop)->create(), 'fertilizer-recommendations', ['crop_color', 'crop.crop_color']],
    'seeds' => [fn (Crop $crop): Model => Seed::factory()->for($crop)->create(), 'seeds', ['crop_color']],
    'seed lots' => [fn (Crop $crop): Model => SeedLot::factory()->for(Seed::factory()->for($crop)->create())->create(), 'seed-lots', ['seed.crop_color']],
    'seed prices' => [fn (Crop $crop): Model => SeedPrice::factory()->for(Seed::factory()->for($crop)->create())->create(), 'seed-prices', ['seed.crop_color']],
]);

test('smart timeline preview exposes the selected crop color independently of language', function (string $locale) {
    $admin = AdminUser::factory()->create();
    $crop = Crop::factory()->published()->withColor('#FFC107')->create();
    $season = Season::factory()->withNames()->create(['is_active' => true]);
    $country = Region::factory()->create();
    $state = Region::factory()->under($country)->create();
    $district = Region::factory()->under($state)->create();

    $this->withToken($admin->createToken('crop-color', ['admin'])->plainTextToken)->withHeader('X-Locale', $locale)
        ->postJson('/api/admin/farmer-smart-timeline/preview', ['crop_id' => $crop->id, 'season_id' => $season->id,
            'sowing_date' => '2026-11-15', 'district_id' => $district->id])->assertOk()
        ->assertJsonPath('data.selection.crop_color', '#FFC107')->assertJsonPath('data.preview_only', true);
})->with(['en', 'hi']);

test('crop color migration preserves existing crop data and restores a nullable column after rollback', function () {
    $crop = Crop::factory()->withNames()->withColor('#FFC107')->create();
    $before = $crop->fresh()->getRawOriginal();
    unset($before['crop_color']);
    $translations = $crop->translations()->get()->toArray();
    $migration = require database_path('migrations/2026_11_15_165435_add_crop_color_to_crops_table.php');

    $migration->down();

    expect(Schema::hasColumn('crops', 'crop_color'))->toBeFalse();
    expect((array) DB::table('crops')->where('id', $crop->id)->first())->toBe($before);
    $migration->up();
    $this->assertDatabaseHas('crops', ['id' => $crop->id, 'crop_color' => null]);
    expect($crop->translations()->get()->toArray())->toBe($translations);
    expect(Schema::hasColumn('crop_translations', 'crop_color'))->toBeFalse();
});

test('crop factories keep colors nullable and can generate valid hex colors explicitly', function () {
    expect(Crop::factory()->make(['category_id' => 1])->crop_color)->toBeNull();
    expect(Crop::factory()->withColor()->make(['category_id' => 1])->crop_color)->toMatch('/^#[0-9a-fA-F]{6}$/D');
});

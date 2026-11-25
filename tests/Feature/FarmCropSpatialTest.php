<?php

use App\Models\Farm;
use App\Models\FarmCropCycle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../FarmCropFixtures.php';

/** @param Closure(): void $test */
function withCropSpatialDatabase(Closure $test): void
{
    $original = DB::getDefaultConnection();
    $connection = config('database.connections.mysql');
    $name = 'bhoomitraa_crop_test_'.bin2hex(random_bytes(6));
    $connection['database'] = $name;
    $connection['url'] = null;
    $connection['engine'] = 'InnoDB';
    $server = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4', $connection['username'], $connection['password'], $connection['options']);
    $created = false;
    try {
        $server->exec('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        config()->set('database.connections.crop_spatial_test', $connection);
        DB::setDefaultConnection('crop_spatial_test');
        expect(Artisan::call('migrate', ['--database' => 'crop_spatial_test', '--force' => true, '--no-interaction' => true]))->toBe(0);
        $test();
    } finally {
        DB::purge('crop_spatial_test');
        DB::setDefaultConnection($original);
        if ($created && preg_match('/\Abhoomitraa_crop_test_[a-f0-9]{12}\z/', $name) === 1) {
            $server->exec('DROP DATABASE '.$name);
        }
    }
}

function nativeCropReady(TestCase $test, array $context, array $boundary, string $source = 'manual'): array
{
    $draft = cropManagerDraft($test, $context);
    $id = $draft['id'];

    return $test->putJson('/api/account/farm-crops/'.$id.'/step-2', ['revision' => 1, 'boundary_source' => $source, 'boundary' => $boundary,
        'area_unit' => 'hectare', 'gps_accuracy_m' => 3.2])->assertOk()->json('data');
}

test('saves and activates a crop matching the precise farm boundary', function (string $source) {
    if (getenv('FARM_CROP_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_CROP_SPATIAL_DATABASE_TESTS=1 to run isolated native spatial tests.');
    }
    withCropSpatialDatabase(function () use ($source): void {
        $context = cropManagerContext();
        $full = cropManagerPolygon(75.12345678912345, 75.12745678912345, 26.12345678912345, 26.12745678912345);
        $farmUrl = '/api/account/farms/'.$context['farm']->id;
        $this->withToken($context['user']->createToken('native-crop', ['frontend'])->plainTextToken)
            ->patchJson($farmUrl.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $boundary = $this->getJson($farmUrl)->assertOk()->json('data.boundary');

        $ready = nativeCropReady($this, $context, $boundary, $source);
        $this->postJson('/api/account/farm-crops/'.$ready['id'].'/finalize', ['revision' => 2])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.boundary_details.boundary', $full);

        $this->assertDatabaseHas('farm_crop_cycles', ['id' => $ready['id'], 'status' => 'active', 'boundary_source' => $source]);
        $this->patchJson($farmUrl.'/boundary', ['boundary' => $boundary, 'boundary_source' => 'manual'])->assertOk();
    });
})->with([
    'saved farm boundary' => ['farm_boundary'],
    'matching manual boundary' => ['manual'],
    'matching GPS boundary' => ['gps'],
]);

test('saves a smaller crop sharing a precise farm edge', function () {
    if (getenv('FARM_CROP_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_CROP_SPATIAL_DATABASE_TESTS=1 to run isolated native spatial tests.');
    }
    withCropSpatialDatabase(function (): void {
        $context = cropManagerContext();
        $full = cropManagerPolygon(75.12345678912345, 75.12745678912345, 26.12345678912345, 26.12745678912345);
        $this->withToken($context['user']->createToken('native-crop', ['frontend'])->plainTextToken)
            ->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $draft = cropManagerDraft($this, $context);
        $boundary = cropManagerPolygon(75.12345678912345, 75.125, 26.124, 26.126);

        $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual', 'boundary' => $boundary])
            ->assertOk()->assertJsonPath('data.boundary_details.boundary', $boundary);

        $this->assertDatabaseHas('farm_crop_cycles', ['id' => $draft['id'], 'revision' => 2, 'boundary_source' => 'manual']);
    });
});

test('returns 422 without saving crop boundaries extending outside the farm', function (array $boundary) {
    if (getenv('FARM_CROP_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_CROP_SPATIAL_DATABASE_TESTS=1 to run isolated native spatial tests.');
    }
    withCropSpatialDatabase(function () use ($boundary): void {
        $context = cropManagerContext();
        $full = cropManagerPolygon(75.12345678912345, 75.12745678912345, 26.12345678912345, 26.12745678912345);
        $this->withToken($context['user']->createToken('native-crop', ['frontend'])->plainTextToken)
            ->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $draft = cropManagerDraft($this, $context);

        $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual', 'boundary' => $boundary])
            ->assertUnprocessable()->assertJsonValidationErrors('boundary')
            ->assertJsonPath('errors.boundary.0', 'The crop boundary must be inside or equal to the farm boundary.');

        $this->assertDatabaseHas('farm_crop_cycles', ['id' => $draft['id'], 'revision' => 1, 'boundary' => null, 'cultivated_area' => null]);
    });
})->with([
    'larger than the farm' => [cropManagerPolygon(75.12, 75.13, 26.12, 26.13)],
    'smaller but crossing the farm edge' => [cropManagerPolygon(75.126, 75.128, 26.124, 26.126)],
    'same size but shifted outside' => [cropManagerPolygon(75.12445678912345, 75.12845678912345, 26.12345678912345, 26.12745678912345)],
]);

test('native spatial allocations support adjacent crops union occupancy finalization races and parent boundary safeguards', function () {
    if (getenv('FARM_CROP_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_CROP_SPATIAL_DATABASE_TESTS=1 to run isolated native spatial and concurrency tests.');
    }
    withCropSpatialDatabase(function (): void {
        $context = cropManagerContext();
        $full = cropManagerPolygon(75, 75.004, 26, 26.004);
        $left = cropManagerPolygon(75.0005, 75.002, 26.0005, 26.0035);
        $right = cropManagerPolygon(75.002, 75.0035, 26.0005, 26.0035);
        $token = $context['user']->createToken('native-crop', ['frontend'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $first = nativeCropReady($this, $context, $left);
        $this->getJson('/api/account/farms/'.$context['farm']->id.'/crop-boundaries')->assertOk()->assertJsonCount(0, 'data.existing_active_crops');
        $this->postJson('/api/account/farm-crops/'.$first['id'].'/finalize', ['revision' => 2])->assertOk();
        $second = nativeCropReady($this, $context, $right, 'gps');
        $this->postJson('/api/account/farm-crops/'.$second['id'].'/finalize', ['revision' => 2])->assertOk();
        $stored = DB::selectOne('SELECT ST_SRID(boundary) AS srid FROM farm_crop_cycles WHERE id = ?', [$first['id']]);
        expect((int) $stored->srid)->toBe(4326);
        $this->getJson('/api/account/farm-crops/map?per_page=1')->assertOk()->assertJsonCount(2, 'data');
        $overview = $this->getJson('/api/account/farms/'.$context['farm']->id.'/crop-boundaries')->assertOk()
            ->assertJsonCount(2, 'data.existing_active_crops')->assertJsonPath('data.can_use_full_farm_boundary', false);
        $occupied = (float) $overview->json('data.existing_crop_area.square_metres');
        expect($occupied)->toBeGreaterThan(0);
        expect((float) $overview->json('data.remaining_area.square_metres'))->toBeGreaterThan(0);
        $third = cropManagerDraft($this, $context);
        $this->putJson('/api/account/farm-crops/'.$third['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'farm_boundary'])->assertConflict();
        $this->putJson('/api/account/farm-crops/'.$third['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual', 'boundary' => cropManagerPolygon(74, 74.001, 26, 26.001)])->assertUnprocessable()->assertJsonValidationErrors('boundary');
        $bowtie = ['type' => 'MultiPolygon', 'coordinates' => [[[[75, 26], [75.001, 26.001], [75, 26.001], [75.001, 26], [75, 26]]]]];
        $this->putJson('/api/account/farm-crops/'.$third['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'gps', 'boundary' => $bowtie])->assertUnprocessable()->assertJsonValidationErrors('boundary');
        $this->putJson('/api/account/farm-crops/'.$first['id'].'/step-2', ['revision' => 3, 'boundary_source' => 'manual', 'boundary' => $left])->assertOk();
        $this->deleteJson('/api/account/farm-crops/'.$first['id'].'/edit-draft', ['revision' => 4])->assertOk();
        $this->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => cropManagerPolygon(75, 75.001, 26, 26.001), 'boundary_source' => 'manual'])
            ->assertUnprocessable()->assertJsonValidationErrors('boundary');
        $this->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => null])->assertUnprocessable();
        $this->patchJson('/api/account/farms/'.$context['farm']->id.'/status', ['is_active' => false])->assertUnprocessable();

        $legacy = FarmCropCycle::factory()->create(['farm_id' => $context['farm']->id, 'crop_id' => $context['crop']->id, 'season_id' => $context['season']->id,
            'status' => 'active', 'cultivated_area' => 1, 'area_unit' => 'hectare']);
        DB::transaction(fn () => $legacy->persistBoundary($left));
        $deduplicated = $this->getJson('/api/account/farms/'.$context['farm']->id.'/crop-boundaries')->assertOk()->json('data.existing_crop_area.square_metres');
        expect(abs((float) $deduplicated - $occupied))->toBeLessThan(0.01);
        $legacy->delete();

        $otherFarm = Farm::factory()->for($context['user'])->inDistrict($context['district'])->create();
        $context['farm'] = $otherFarm;
        $this->patchJson('/api/account/farms/'.$otherFarm->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $a = nativeCropReady($this, $context, $left);
        $b = nativeCropReady($this, $context, $left);
        $this->postJson('/api/account/farm-crops/'.$a['id'].'/finalize', ['revision' => 2])->assertOk();
        $this->postJson('/api/account/farm-crops/'.$b['id'].'/finalize', ['revision' => 2])->assertConflict();
        $this->assertDatabaseHas('farm_crop_cycles', ['id' => $b['id'], 'status' => 'draft']);

        $raceFarm = Farm::factory()->for($context['user'])->inDistrict($context['district'])->create();
        $context['farm'] = $raceFarm;
        $this->patchJson('/api/account/farms/'.$raceFarm->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $racing = [nativeCropReady($this, $context, $left), nativeCropReady($this, $context, $left)];
        $connection = config('database.connections.crop_spatial_test');
        $barrier = storage_path('framework/testing/crop-race-'.bin2hex(random_bytes(6)));
        mkdir($barrier, 0700, true);
        $workers = [];
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set('database.connections.crop_spatial_test', $input['connection']);
Illuminate\Support\Facades\DB::setDefaultConnection('crop_spatial_test');
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($input['go'])) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Crop activation barrier timed out.'); }
    usleep(10000);
}
$request = Illuminate\Http\Request::create('/api/account/farm-crops/'.$input['id'].'/finalize', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], content: '{"revision":2}');
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;
        try {
            foreach ($racing as $index => $draft) {
                $process = new Process([PHP_BINARY, '-r', $worker, base_path()], base_path(), timeout: 30);
                $process->setInput(json_encode(['connection' => $connection, 'id' => $draft['id'], 'token' => $token,
                    'ready' => $barrier.'/ready-'.$index, 'go' => $barrier.'/go'], JSON_THROW_ON_ERROR));
                $process->start();
                $workers[] = $process;
            }
            $deadline = microtime(true) + 20;
            while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
                foreach ($workers as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Crop activation worker stopped: '.$process->getOutput().$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Crop activation workers did not become ready.');
                }
                usleep(10000);
            }
            file_put_contents($barrier.'/go', 'go');
            $statuses = [];
            foreach ($workers as $process) {
                expect($process->wait())->toBe(0, $process->getErrorOutput());
                $statuses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            expect($statuses)->toBe([200, 409]);
            expect($raceFarm->cropCycles()->where('status', 'active')->count())->toBe(1);
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (['ready-0', 'ready-1', 'go'] as $file) {
                if (is_file($barrier.'/'.$file)) {
                    unlink($barrier.'/'.$file);
                }
            }
            rmdir($barrier);
        }
    });
});

test('MySQL validates holes and multiple polygon components using geographic topology', function () {
    if (getenv('FARM_CROP_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_CROP_SPATIAL_DATABASE_TESTS=1 against MySQL 8.4.');
    }
    withCropSpatialDatabase(function (): void {
        if (DB::connection()->isMaria()) {
            $this->markTestSkipped('MySQL 8.4 is required; the available server is MariaDB.');
        }
        $context = cropManagerContext();
        $full = cropManagerPolygon(75, 75.004, 26, 26.004);
        $this->withToken($context['user']->createToken('native-crop', ['frontend'])->plainTextToken)
            ->patchJson('/api/account/farms/'.$context['farm']->id.'/boundary', ['boundary' => $full, 'boundary_source' => 'manual'])->assertOk();
        $draft = cropManagerDraft($this, $context);
        $parcel = cropManagerPolygon(75.0005, 75.0035, 26.0005, 26.0035);
        $hole = cropManagerPolygon(75.001, 75.002, 26.001, 26.002)['coordinates'][0][0];
        $parcel['coordinates'][0][] = array_reverse($hole);
        $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 1, 'boundary_source' => 'manual', 'boundary' => $parcel])->assertOk();
        $parcel['coordinates'][0][1] = array_reverse(cropManagerPolygon(75.01, 75.02, 26.01, 26.02)['coordinates'][0][0]);
        $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 2, 'boundary_source' => 'manual', 'boundary' => $parcel])->assertUnprocessable();
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [
            cropManagerPolygon(75.0005, 75.0015, 26.0005, 26.0015)['coordinates'][0],
            cropManagerPolygon(75.002, 75.003, 26.002, 26.003)['coordinates'][0],
        ]];
        $this->putJson('/api/account/farm-crops/'.$draft['id'].'/step-2', ['revision' => 2, 'boundary_source' => 'manual', 'boundary' => $multi])->assertOk();
    });
});

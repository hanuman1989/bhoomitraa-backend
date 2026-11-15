<?php

use App\Models\Farm;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

test('native farm geometry preserves SRID axis order constraints and concurrent creation idempotency', function () {
    if (getenv('FARM_SPATIAL_DATABASE_TESTS') !== '1') {
        $this->markTestSkipped('Set FARM_SPATIAL_DATABASE_TESTS=1 to test an isolated temporary MySQL or MariaDB database.');
    }
    $originalConnection = config('database.default');
    $connection = config('database.connections.mysql');
    $database = 'bhoomitraa_farm_test_'.bin2hex(random_bytes(6));
    $connection['database'] = $database;
    $connection['url'] = null;
    $connection['engine'] = 'InnoDB';
    $server = new PDO('mysql:host='.$connection['host'].';port='.$connection['port'].';charset=utf8mb4',
        $connection['username'], $connection['password'], $connection['options']);
    $server->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $barrier = storage_path('framework/testing/'.$database);
    $workers = [];
    $created = false;

    try {
        $server->exec('CREATE DATABASE '.$database.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        config()->set('database.connections.farm_spatial_test', $connection);
        DB::setDefaultConnection('farm_spatial_test');
        $upgradePath = database_path('migrations/2026_11_15_135002_centralize_farm_area_measurements.php');
        $legacyPaths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => $path !== $upgradePath));
        expect(Artisan::call('migrate', ['--database' => 'farm_spatial_test', '--path' => $legacyPaths,
            '--realpath' => true, '--force' => true, '--no-interaction' => true]))->toBe(0);
        $upgrade = require $upgradePath;
        $upgrade->up();
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeFalse();
        $upgrade->down();
        expect(Schema::hasColumn('farms', 'actual_area_sqm'))->toBeTrue();
        $legacy = Farm::factory()->create(['area' => '4.000', 'area_unit' => 'bigha']);
        $legacyBoundary = 'MULTIPOLYGON(((75.78 26.76,75.781 26.76,75.781 26.761,75.78 26.761,75.78 26.76)))';
        $legacyGeometry = DB::connection()->isMaria() ? 'ST_GeomFromText(?, 4326)' : "ST_GeomFromText(?, 4326, 'axis-order=long-lat')";
        DB::update('UPDATE farms SET boundary = '.$legacyGeometry.", boundary_source = 'manual', actual_area = ?, actual_area_unit = ?, actual_area_sqm = ? WHERE id = ?",
            [$legacyBoundary, '0.395', 'ha', '3946.970097', $legacy->id]);
        $legacyBefore = $legacy->fresh()->getRawOriginal();
        expect(Artisan::call('migrate', ['--database' => 'farm_spatial_test', '--force' => true, '--no-interaction' => true]))->toBe(0);
        expect($legacy->fresh()->actual_area_sqm)->toBe('3946.970097');
        expect($legacy->fresh()->actual_area_unit)->toBe('sqm');
        expect($legacy->fresh()->area_unit)->toBe('bigha');
        expect($legacy->fresh()->getRawOriginal('boundary'))->toBe($legacyBefore['boundary']);
        expect($legacy->fresh()->getRawOriginal('updated_at'))->toBe($legacyBefore['updated_at']);
        $columns = Schema::getColumnListing('farms');
        expect($columns)->toContain('user_id', 'name', 'description', 'ownership_type', 'area', 'area_unit', 'area_source',
            'actual_area', 'actual_area_unit', 'region_id', 'state_id', 'district_id', 'tehsil_id',
            'village', 'address', 'latitude', 'longitude', 'boundary', 'boundary_source', 'gps_accuracy_m',
            'khasra_number', 'khata_number', 'patwari_halka_number', 'soil_type', 'land_type', 'farmland_type',
            'water_availability', 'irrigation_source', 'notes', 'is_active', 'created_at', 'updated_at');
        $ddl = strtolower(array_values((array) DB::selectOne('SHOW CREATE TABLE farms'))[1]);
        expect($columns)->not->toContain('area_sqm', 'actual_area_sqm', 'area_unit_conversion_id');
        expect($ddl)->toContain('multipolygon', 'decimal(12,3)', 'decimal(18,8)', 'datetime(6)', 'set null');
        expect($ddl)->not->toContain('spatial key');
        if (! DB::connection()->isMaria()) {
            $column = DB::selectOne("SELECT SRS_ID AS srid FROM information_schema.columns WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'farms' AND COLUMN_NAME = 'boundary'", [$database]);
            expect((int) $column->srid)->toBe(4326);
        }

        $country = Region::factory()->create();
        $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
        $district = Region::factory()->under($state)->withEnglishName('Jaipur')->create();
        $farmer = User::factory()->create();
        $token = $farmer->createToken('native-test', ['frontend'])->plainTextToken;
        $boundary = ['type' => 'MultiPolygon', 'coordinates' => [[[
            [75.78, 26.76], [75.781, 26.76], [75.781, 26.761], [75.78, 26.761], [75.78, 26.76],
        ]]]];
        $payload = ['name' => 'Native spatial farm', 'area' => '10.000', 'area_unit' => 'bigha_pakka',
            'state_id' => $state->id, 'district_id' => $district->id, 'village' => 'Rampura',
            'boundary' => $boundary, 'boundary_source' => 'gps', 'gps_accuracy_m' => '4.80'];
        $id = $this->withToken($token)->postJson('/api/account/farms', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.boundary.coordinates.0.0.0', [75.78, 26.76])
            ->assertJsonPath('data.area', '10.000')->assertJsonPath('data.actual_area_unit', 'bigha_pakka')
            ->assertJsonPath('data.boundary_source', 'gps')->json('data.id');
        $stored = DB::selectOne('SELECT ST_SRID(boundary) AS srid, ST_GeometryType(boundary) AS kind FROM farms WHERE id = ?', [$id]);
        expect((int) $stored->srid)->toBe(4326);
        expect(strtoupper($stored->kind))->toBe('MULTIPOLYGON');
        expect((float) Farm::findOrFail($id)->actual_area_sqm)->toBeGreaterThan(10000)->toBeLessThan(12000);
        $this->getJson('/api/account/farms?include_boundary=1')->assertOk()->assertJsonPath('data.0.boundary.coordinates.0.0.1', [75.781, 26.76]);
        expect(fn () => DB::update('UPDATE farms SET boundary = ST_GeomFromText(?, 0) WHERE id = ?', [
            'MULTIPOLYGON(((75.78 26.76,75.781 26.76,75.781 26.761,75.78 26.761,75.78 26.76)))', $id,
        ]))->toThrow(QueryException::class);
        expect(fn () => DB::table('farms')->where('id', $id)->update(['latitude' => null]))->toThrow(QueryException::class);
        expect(fn () => DB::table('farms')->where('id', $id)->update(['area' => 0]))->toThrow(QueryException::class);
        expect(fn () => DB::table('users')->where('id', $farmer->id)->delete())->toThrow(QueryException::class);

        mkdir($barrier, 0700, true);
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set('database.connections.farm_spatial_test', $input['connection']);
Illuminate\Support\Facades\DB::setDefaultConnection('farm_spatial_test');
$route = app('router')->getRoutes()->getByName('account.farms.store');
$action = $route->getAction();
unset($action['controller']);
$action['uses'] = function (App\Http\Requests\Account\StoreFarmRequest $request) use ($input) {
    file_put_contents($input['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent farm request barrier timed out.');
        }
        usleep(10000);
    }
    return app(App\Http\Controllers\Account\FarmController::class)->store($request);
};
$route->setAction($action);
$request = Illuminate\Http\Request::create('/api/account/farms', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IDEMPOTENCY_KEY' => $input['key'],
], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo json_encode(['code' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;
        $key = (string) Str::uuid();
        $payload['name'] = 'Concurrent farm';
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-r', $worker, base_path()], base_path(), timeout: 30);
            $process->setInput(json_encode(['connection' => $connection, 'payload' => $payload, 'token' => $token,
                'key' => $key, 'ready' => $barrier.'/ready-'.$index, 'go' => $barrier.'/go'], JSON_THROW_ON_ERROR));
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
            foreach ($workers as $process) {
                if (! $process->isRunning()) {
                    $this->fail('A native farm request failed before validation: '.$process->getOutput().$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Concurrent farm requests did not reach the persistence barrier.');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'/go', 'go');
        $ids = [];
        foreach ($workers as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $response = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($response['code'])->toBe(201);
            expect(array_keys($response['body']))->toBe(['status', 'data', 'pagination', 'message']);
            $ids[] = $response['body']['data']['id'];
        }
        expect($ids[0])->toBe($ids[1]);
        expect($farmer->farms()->count())->toBe(2);
        $this->patchJson('/api/account/farms/'.$id.'/boundary', ['boundary' => null])->assertOk()
            ->assertJsonPath('data.boundary', null)->assertJsonPath('data.actual_area_sqm', null)
            ->assertJsonPath('data.gps_accuracy_m', null)->assertJsonPath('data.area', '10.000');
        expect(DB::table('farms')->where('id', $id)->value('boundary'))->toBeNull();
    } finally {
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('farm_spatial_test');
        DB::setDefaultConnection($originalConnection);
        if ($created && preg_match('/\Abhoomitraa_farm_test_[a-f0-9]{12}\z/', $database) === 1) {
            $server->exec('DROP DATABASE '.$database);
        }
        foreach (['ready-0', 'ready-1', 'go'] as $file) {
            if (is_file($barrier.'/'.$file)) {
                unlink($barrier.'/'.$file);
            }
        }
        if (is_dir($barrier)) {
            rmdir($barrier);
        }
    }
});

<?php

use App\Models\AdminUser;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\KnowledgeSource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('simultaneous first active recommendation submissions cannot both commit on InnoDB', function () {
    if (getenv('CROP_SEED_RATE_MYSQL_CONCURRENCY') !== '1') {
        $this->markTestSkipped('Set CROP_SEED_RATE_MYSQL_CONCURRENCY=1 to run against an isolated temporary MySQL database.');
    }

    $originalConnection = config('database.default');
    $connection = config('database.connections.mysql');
    $database = 'bhoomitraa_rate_test_'.bin2hex(random_bytes(6));
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
        $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $created = true;
        config()->set('database.connections.rate_concurrency', $connection);
        DB::setDefaultConnection('rate_concurrency');
        expect(Artisan::call('migrate', ['--database' => 'rate_concurrency', '--force' => true, '--no-interaction' => true]))->toBe(0);
        if (! is_dir($barrier)) {
            mkdir($barrier, 0700, true);
        }

        $crop = Crop::factory()->published()->create();
        $admin = AdminUser::factory()->superUser()->create();
        $payload = ['crop_id' => $crop->id, 'material_type' => 'seed', 'rate_basis' => 'per_area',
            'rate_recommended' => '4.0000', 'rate_unit_code' => 'kg', 'area_unit_code' => 'ha',
            'region_scope' => 'all', 'source_id' => KnowledgeSource::factory()->active()->create()->id, 'is_active' => true];

        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set('database.connections.rate_concurrency', $input['connection']);
Illuminate\Support\Facades\DB::setDefaultConnection('rate_concurrency');
$route = app('router')->getRoutes()->getByName('admin.crop-seed-rates.store');
$action = $route->getAction();
unset($action['controller']);
$action['uses'] = function (App\Http\Requests\Admin\StoreCropSeedRateRequest $request) use ($input) {
    file_put_contents($input['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent request barrier timed out.');
        }
        usleep(10000);
    }
    return app(App\Http\Controllers\Admin\CropSeedRateController::class)->store($request);
};
$route->setAction($action);
$request = Illuminate\Http\Request::create('/api/admin/crop-seed-rates', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo json_encode(['code' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;

        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-r', $worker, base_path()], base_path(), timeout: 30);
            $process->setInput(json_encode(['connection' => $connection,
                'token' => $admin->createToken('concurrent-'.$index, ['admin'])->plainTextToken,
                'payload' => $payload, 'ready' => $barrier.'/ready-'.$index, 'go' => $barrier.'/go'], JSON_THROW_ON_ERROR));
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 20;
        while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
            foreach ($workers as $process) {
                if (! $process->isRunning()) {
                    $this->fail('A concurrent request failed before validation completed: '.$process->getOutput().$process->getErrorOutput());
                }
            }
            if (microtime(true) > $deadline) {
                $this->fail('Concurrent requests did not reach the persistence barrier.');
            }
            usleep(10000);
        }
        file_put_contents($barrier.'/go', 'go');
        $codes = [];
        foreach ($workers as $process) {
            expect($process->wait())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect(array_keys($result['body']))->toBe(['status', 'data', 'pagination', 'message']);
            $codes[] = $result['code'];
        }
        sort($codes);
        expect($codes)->toBe([201, 409]);
        expect(CropSeedRate::query()->where('crop_id', $crop->id)->where('is_active', true)->count())->toBe(1);
        expect(DB::table('crop_seed_rate_regions')->count())->toBe(0);
    } finally {
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('rate_concurrency');
        DB::setDefaultConnection($originalConnection);
        if ($created && preg_match('/\Abhoomitraa_rate_test_[a-f0-9]{12}\z/', $database) === 1) {
            $server->exec('DROP DATABASE `'.$database.'`');
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

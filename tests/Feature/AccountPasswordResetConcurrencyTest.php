<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Process\Process;

test('simultaneous requests cannot consume the same farmer reset token twice on InnoDB', function () {
    if (getenv('ACCOUNT_PASSWORD_RESET_MYSQL_CONCURRENCY') !== '1') {
        $this->markTestSkipped('Set ACCOUNT_PASSWORD_RESET_MYSQL_CONCURRENCY=1 to run against an isolated temporary MySQL database.');
    }

    $originalConnection = config('database.default');
    $connection = config('database.connections.mysql');
    $database = 'bhoomitraa_reset_test_'.bin2hex(random_bytes(6));
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
        config()->set('database.connections.reset_concurrency', $connection);
        DB::setDefaultConnection('reset_concurrency');
        expect(Artisan::call('migrate', ['--database' => 'reset_concurrency', '--force' => true, '--no-interaction' => true]))->toBe(0);
        if (! is_dir($barrier)) {
            mkdir($barrier, 0700, true);
        }

        $user = User::factory()->create(['remember_token' => 'previous-token']);
        $user->createToken('existing', ['frontend']);
        $payload = ['email' => $user->email, 'token' => Password::broker('users')->createToken($user),
            'password' => 'NewSecurePassword123!', 'password_confirmation' => 'NewSecurePassword123!'];

        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$app = require $argv[1].'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
config()->set('database.connections.reset_concurrency', $input['connection']);
Illuminate\Support\Facades\DB::setDefaultConnection('reset_concurrency');
$route = app('router')->getRoutes()->getByName('account.reset-password');
$action = $route->getAction();
unset($action['controller']);
$action['uses'] = function (App\Http\Requests\Account\ResetPasswordRequest $request) use ($input) {
    file_put_contents($input['ready'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['go'])) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Concurrent request barrier timed out.');
        }
        usleep(10000);
    }
    return app(App\Http\Controllers\Account\UserController::class)->resetPassword($request);
};
$route->setAction($action);
$request = Illuminate\Http\Request::create('/api/account/reset-password', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
echo json_encode(['code' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;

        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-r', $worker, base_path()], base_path(), timeout: 30);
            $process->setInput(json_encode(['connection' => $connection,
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
            expect(array_keys($result['body']))->toBe($result['code'] === 200 ? ['status', 'data', 'pagination', 'message'] : ['status', 'data', 'pagination', 'message', 'errors']);
            $codes[] = $result['code'];
        }
        sort($codes);
        expect($codes)->toBe([200, 422]);
        expect(Hash::check('NewSecurePassword123!', $user->fresh()->password))->toBeTrue();
        expect($user->fresh()->remember_token)->not->toBe('previous-token');
        expect(DB::table('password_reset_tokens')->count())->toBe(0);
        expect($user->tokens()->count())->toBe(0);
    } finally {
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        DB::purge('reset_concurrency');
        DB::setDefaultConnection($originalConnection);
        if ($created && preg_match('/\Abhoomitraa_reset_test_[a-f0-9]{12}\z/', $database) === 1) {
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

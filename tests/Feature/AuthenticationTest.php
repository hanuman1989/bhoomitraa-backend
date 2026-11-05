<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

test('login database lookup failures return a safe 500 and are reported', function (string $model, string $prefix) {
    Schema::drop((new $model)->getTable());
    Exceptions::fake();

    $response = $this->postJson($prefix.'/login', ['email' => 'user@example.com', 'password' => 'password']);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null,
        'message' => 'Unable to log in. Please try again later.',
        'pagination' => null,
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Exceptions::assertReported(QueryException::class);
})->with([
    'admin' => [AdminUser::class, '/api/admin'],
    'frontend' => [User::class, '/api'],
]);

test('failed token creation returns 500 and issues no login token', function (string $model, string $prefix) {
    $account = $model::factory()->create();
    DB::unprepared("CREATE TRIGGER fail_token_insert BEFORE INSERT ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->postJson($prefix.'/login', ['email' => $account->email, 'password' => 'password']);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('message', 'Unable to log in. Please try again later.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Exceptions::assertReported(QueryException::class);
})->with([
    'admin' => [AdminUser::class, '/api/admin'],
    'frontend' => [User::class, '/api'],
]);

test('failed logout returns 500 and retains the current token', function (string $model, string $prefix, string $ability) {
    $account = $model::factory()->create();
    $token = $account->createToken('test', [$ability]);
    DB::unprepared("CREATE TRIGGER fail_logout_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token->plainTextToken)->postJson($prefix.'/logout');

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('message', 'Unable to log out. Please try again later.');
    $this->assertModelExists($token->accessToken);
    Exceptions::assertReported(QueryException::class);
})->with([
    'admin' => [AdminUser::class, '/api/admin', 'admin'],
    'frontend' => [User::class, '/api', 'frontend'],
]);

test('login issues a scoped bearer token and hides sensitive account fields', function (string $model, string $prefix, string $accountKey, string $ability) {
    $this->freezeTime();
    $account = $model::factory()->create();

    $response = $this->postJson($prefix.'/login', [
        'email' => $account->email,
        'password' => 'password',
        'device_name' => 'test-client',
        'abilities' => ['*'],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', true)
        ->assertJsonPath('data.'.$accountKey.'.id', $account->id)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_at', now()->addDay()->toISOString())
        ->assertJsonMissingPath('data.'.$accountKey.'.password')
        ->assertJsonMissingPath('data.'.$accountKey.'.remember_token')
        ->assertJsonPath('pagination', null)
        ->assertJsonMissingPath('errors');

    $token = PersonalAccessToken::findToken($response->json('data.access_token'));
    expect($token->tokenable->is($account))->toBeTrue();
    expect($token->abilities)->toBe([$ability]);
    expect($token->name)->toBe('test-client');
    expect($token->token)->not->toBe($response->json('data.access_token'));
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
})->with([
    'admin' => [AdminUser::class, '/api/admin', 'admin_user', 'admin'],
    'frontend' => [User::class, '/api', 'user', 'frontend'],
]);

test('an account can retrieve its profile with a real bearer token', function (string $model, string $prefix, string $ability) {
    $account = $model::factory()->create();
    $token = $account->createToken('test', [$ability])->plainTextToken;

    $response = $this->withToken($token)->getJson($prefix.'/me');

    $response->assertOk()->assertJsonPath('data.id', $account->id)->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
})->with([
    'admin' => [AdminUser::class, '/api/admin', 'admin'],
    'frontend' => [User::class, '/api', 'frontend'],
]);

test('logout revokes only the current token', function (string $model, string $prefix, string $ability) {
    $account = $model::factory()->create();
    $currentToken = $account->createToken('current', [$ability]);
    $otherToken = $account->createToken('other', [$ability]);

    $response = $this->withToken($currentToken->plainTextToken)->postJson($prefix.'/logout');

    $response->assertOk()->assertJsonPath('status', true)->assertJsonPath('data', null);
    $this->assertModelMissing($currentToken->accessToken);
    $this->assertModelExists($otherToken->accessToken);
})->with([
    'admin' => [AdminUser::class, '/api/admin', 'admin'],
    'frontend' => [User::class, '/api', 'frontend'],
]);

test('a token for the other account model receives 401 even with wildcard abilities and matching IDs', function (string $sourceModel, string $targetModel, string $targetPrefix) {
    $source = $sourceModel::factory()->create(['id' => 1, 'email' => 'shared@example.com']);
    $targetModel::factory()->create(['id' => 1, 'email' => 'shared@example.com']);
    $token = $source->createToken('test', ['*'])->plainTextToken;

    $response = $this->withToken($token)->getJson($targetPrefix.'/me');

    $response->assertUnauthorized()->assertExactJson([
        'status' => false,
        'data' => null,
        'message' => 'Unauthenticated.',
        'pagination' => null,
    ]);
})->with([
    'frontend cannot access admin' => [User::class, AdminUser::class, '/api/admin'],
    'admin cannot access frontend' => [AdminUser::class, User::class, '/api'],
]);

test('login rejects credentials from the other table with 401', function (string $sourceModel, string $targetPrefix) {
    $account = $sourceModel::factory()->create();

    $response = $this->postJson($targetPrefix.'/login', ['email' => $account->email, 'password' => 'password']);

    $response->assertUnauthorized()->assertJsonPath('status', false);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'frontend credentials at admin login' => [User::class, '/api/admin'],
    'admin credentials at frontend login' => [AdminUser::class, '/api'],
]);

test('login rejects an incorrect password with 401 and issues no tokens', function (string $model, string $prefix) {
    $account = $model::factory()->create();

    $response = $this->postJson($prefix.'/login', ['email' => $account->email, 'password' => 'wrong-password']);

    $response->assertUnauthorized()->assertJsonPath('data', null);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'admin' => [AdminUser::class, '/api/admin'],
    'frontend' => [User::class, '/api'],
]);

test('inactive admins cannot log in and receive 401', function () {
    $adminUser = AdminUser::factory()->inactive()->create();

    $response = $this->postJson('/api/admin/login', ['email' => $adminUser->email, 'password' => 'password']);

    $response->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials or inactive account.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('login rejects invalid stored password hashes with 401 and creates no tokens', function (string $model, string $prefix, string $storedPassword) {
    $account = $model::factory()->create();
    DB::table($account->getTable())->where('id', $account->id)->update(['password' => $storedPassword]);

    $response = $this->postJson($prefix.'/login', ['email' => $account->email, 'password' => 'password']);

    $response->assertUnauthorized()
        ->assertJsonPath('status', false)
        ->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)
        ->assertJsonMissingPath('errors');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->assertDatabaseHas($account->getTable(), ['id' => $account->id, 'password' => $storedPassword]);
})->with([
    'admin plaintext' => [AdminUser::class, '/api/admin', 'password'],
    'frontend plaintext' => [User::class, '/api', 'password'],
    'admin malformed bcrypt' => [AdminUser::class, '/api/admin', '$2y$invalid'],
    'frontend malformed bcrypt' => [User::class, '/api', '$2y$invalid'],
    'admin empty password' => [AdminUser::class, '/api/admin', ''],
    'frontend empty password' => [User::class, '/api', ''],
]);

test('a previously issued token cannot access an inactive admin account and receives 403', function () {
    $adminUser = AdminUser::factory()->create();
    $token = $adminUser->createToken('test', ['admin'])->plainTextToken;
    $adminUser->update(['status' => false]);

    $response = $this->withToken($token)->getJson('/api/admin/me');

    $response->assertForbidden()->assertJsonPath('message', 'Admin account is inactive.');
});

test('missing authentication returns a JSON 401 without an Accept header', function (string $path) {
    $response = $this->get($path);

    $response->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.')->assertJsonPath('status', false);
})->with(['/api/admin/me', '/api/me']);

test('web sessions cannot authenticate bearer-only endpoints and receive 401', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'web');

    $response = $this->getJson('/api/me');

    $response->assertUnauthorized();
});

test('a token with the wrong ability receives 403', function (string $model, string $prefix, string $wrongAbility) {
    $account = $model::factory()->create();
    $token = $account->createToken('test', [$wrongAbility])->plainTextToken;

    $response = $this->withToken($token)->getJson($prefix.'/me');

    $response->assertForbidden()->assertJsonPath('status', false)->assertJsonPath('data', null);
})->with([
    'admin' => [AdminUser::class, '/api/admin', 'frontend'],
    'frontend' => [User::class, '/api', 'admin'],
]);

test('expired tokens receive 401', function () {
    $this->freezeTime();
    $adminUser = AdminUser::factory()->create();
    $token = $adminUser->createToken('test', ['admin'], now()->subMinute())->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/me');

    $response->assertUnauthorized();
});

test('tokens older than the configured lifetime receive 401', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $token = $user->createToken('test', ['frontend']);
    $token->accessToken->forceFill(['created_at' => now()->subDay()->subMinute()])->save();

    $response = $this->withToken($token->plainTextToken)->getJson('/api/me');

    $response->assertUnauthorized();
});

test('revoked tokens receive 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['frontend']);
    $token->accessToken->delete();

    $response = $this->withToken($token->plainTextToken)->getJson('/api/me');

    $response->assertUnauthorized();
});

test('missing login fields return the standard validation envelope with 422 without an Accept header', function () {
    $response = $this->post('/api/admin/login', []);

    $response->assertUnprocessable()->assertExactJson([
        'status' => false,
        'data' => null,
        'message' => 'Validation failed.',
        'pagination' => null,
        'errors' => [
            'email' => ['The email field is required.'],
            'password' => ['The password field is required.'],
        ],
    ]);
});

test('invalid login fields return 422', function (array $input, string $field) {
    $response = $this->postJson('/api/login', array_replace(['email' => 'user@example.com', 'password' => 'password'], $input));

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'invalid email' => [['email' => 'invalid'], 'email'],
    'email is not a string' => [['email' => ['invalid']], 'email'],
    'email too long' => [['email' => str_repeat('a', 256).'@example.com'], 'email'],
    'password is not a string' => [['password' => ['password']], 'password'],
    'password too long' => [['password' => str_repeat('a', 256)], 'password'],
    'empty device name' => [['device_name' => ''], 'device_name'],
    'device name too long' => [['device_name' => str_repeat('a', 256)], 'device_name'],
]);

test('login rate limits return the standard envelope with 429 and preserve retry headers', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/admin/login', ['email' => 'limited@example.com', 'password' => 'incorrect'])->assertUnauthorized();
    }

    $response = $this->postJson('/api/admin/login', ['email' => 'limited@example.com', 'password' => 'incorrect']);

    $response->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('status', false)->assertJsonPath('message', 'Too many requests. Please try again later.');
});

test('admin and frontend reset tokens remain in separate stores for the same email', function () {
    $this->freezeTime();
    $adminUser = AdminUser::factory()->create(['email' => 'shared@example.com']);
    $user = User::factory()->create(['email' => 'shared@example.com']);
    $frontendToken = Password::broker('users')->createToken($user);

    $adminToken = Password::broker('admin_users')->createToken($adminUser);

    $this->assertDatabaseHas('admin_user_password_reset_tokens', ['email' => 'shared@example.com']);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => 'shared@example.com']);
    expect(Password::broker('admin_users')->tokenExists($adminUser, $adminToken))->toBeTrue();
    expect(Password::broker('users')->tokenExists($user, $frontendToken))->toBeTrue();
    expect(Password::broker('admin_users')->tokenExists($adminUser, $frontendToken))->toBeFalse();
    expect(DB::table('admin_user_password_reset_tokens')->value('token'))->not->toBe($adminToken);
});

test('a configured cross-origin client can preflight bearer requests', function () {
    config(['cors.allowed_origins' => ['https://frontend.example.com']]);

    $response = $this->options('/api/admin/login', [], [
        'Origin' => 'https://frontend.example.com',
        'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'Authorization, Content-Type',
    ]);

    $response->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://frontend.example.com')->assertHeader('Access-Control-Allow-Headers', 'authorization, content-type');
});

test('unknown API routes return the standard 404 envelope', function () {
    $response = $this->get('/api/missing');

    $response->assertNotFound()->assertJsonPath('message', 'Resource not found.')->assertJsonPath('status', false);
});

test('unexpected API errors return a safe 500 envelope and are reported', function () {
    Exceptions::fake();
    Route::get('/api/failing-test', function (): never {
        throw new RuntimeException('secret-internal-error');
    });

    $response = $this->getJson('/api/failing-test');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false,
        'data' => null,
        'message' => 'An unexpected error occurred.',
        'pagination' => null,
    ]);
    Exceptions::assertReported(RuntimeException::class);
});

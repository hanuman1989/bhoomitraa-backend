<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

test('farmer login without remember issues a 24 hour token', function () {
    $this->travelTo('2026-11-08 12:00:00');
    $farmer = User::factory()->create(['mobile' => '9876543210']);

    $response = $this->postJson('/api/account/login', ['login' => '9876543210', 'password' => 'password', 'remember' => false])
        ->assertOk()->assertJsonPath('data.expires_at', '2026-11-09T12:00:00.000000Z');
    $plainToken = $response->json('data.access_token');
    expect(PersonalAccessToken::findToken($plainToken)->expires_at->toISOString())->toBe('2026-11-09T12:00:00.000000Z');

    $this->travelTo('2026-11-09 12:00:01');
    $this->withToken($plainToken)->getJson('/api/account/me')->assertUnauthorized();
});

test('farmer login with remember issues a 30 day token that outlives 24 hours', function () {
    $this->travelTo('2026-11-08 12:00:00');
    $farmer = User::factory()->create(['mobile' => '9876543210']);

    $response = $this->postJson('/api/account/login', ['login' => '9876543210', 'password' => 'password', 'remember' => true])
        ->assertOk()->assertJsonPath('data.expires_at', '2026-12-08T12:00:00.000000Z');
    $plainToken = $response->json('data.access_token');
    expect(PersonalAccessToken::findToken($plainToken)->expires_at->toISOString())->toBe('2026-12-08T12:00:00.000000Z');

    $this->travelTo('2026-12-07 12:00:00');
    $this->withToken($plainToken)->getJson('/api/account/me')->assertOk()->assertJsonPath('data.id', $farmer->id);
    app('auth')->forgetGuards();

    $this->travelTo('2026-12-08 12:00:01');
    $this->withToken($plainToken)->getJson('/api/account/me')->assertUnauthorized();
});

test('farmer login rejects a non boolean remember value', function () {
    User::factory()->create(['mobile' => '9876543210']);

    $this->postJson('/api/account/login', ['login' => '9876543210', 'password' => 'password', 'remember' => 'forever'])
        ->assertUnprocessable()->assertJsonValidationErrors('remember');
});

test('active farmers log in with email or Indian mobile and receive the same scoped expiring bearer token', function (string $login, string $mobile, ?string $email) {
    $this->travelTo('2026-11-08 12:00:00');
    $farmer = User::factory()->create(['first_name' => 'Hanuman', 'last_name' => 'Sharma', 'mobile' => $mobile, 'email' => $email]);
    $response = $this->postJson('/api/account/login', [
        'login' => $login, 'password' => 'password', 'device_name' => 'farmer-phone',
        'abilities' => ['admin', '*'], 'status' => false, 'last_login_at' => '2000-01-01 00:00:00',
    ])->assertOk()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.user.id', $farmer->id)->assertJsonPath('data.user.first_name', 'Hanuman')
        ->assertJsonPath('data.user.last_name', 'Sharma')->assertJsonPath('data.user.mobile', $mobile)
        ->assertJsonPath('data.user.status', true)->assertJsonPath('data.user.last_login_at', '2026-11-08T12:00:00.000000Z')
        ->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.expires_at', '2026-11-09T12:00:00.000000Z')
        ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.remember_token')
        ->assertJsonMissingPath('data.user.deleted_at')->assertJsonMissingPath('data.user.name');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $token = PersonalAccessToken::findToken($response->json('data.access_token'));
    expect($token->tokenable->is($farmer))->toBeTrue();
    expect($token->abilities)->toBe(['frontend']);
    expect($token->name)->toBe('farmer-phone');
    expect($token->token)->not->toBe($response->json('data.access_token'));
    expect($token->expires_at->toISOString())->toBe('2026-11-09T12:00:00.000000Z');
    $this->assertDatabaseHas('users', ['id' => $farmer->id, 'status' => true, 'last_login_at' => '2026-11-08 12:00:00']);
    $this->withToken($response->json('data.access_token'))->getJson('/api/account/me')->assertOk()->assertJsonPath('data.id', $farmer->id);
    $this->postJson('/api/account/logout')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($response->json('data.access_token'))->getJson('/api/account/me')->assertUnauthorized();
})->with([
    'mobile' => ['9876543210', '9876543210', 'farmer@example.com'],
    'mobile starting six' => ['6876543210', '6876543210', 'farmer@example.com'],
    'trimmed mobile without email' => [' 9876543210 ', '9876543210', null],
    'email' => ['farmer@example.com', '9876543210', 'farmer@example.com'],
    'normalized email' => [' FARMER@EXAMPLE.COM ', '9876543210', 'farmer@example.com'],
]);

test('failed farmer logins share a generic 401 and do not issue tokens or update last login', function (string $kind, string $column) {
    $farmer = User::factory()->create(['email' => 'farmer@example.com', 'mobile' => '9876543210']);
    $login = $farmer->{$column};
    $password = 'password';
    if ($kind === 'unknown') {
        $login = $column === 'email' ? 'unknown@example.com' : '9876543211';
    } elseif ($kind === 'wrong password') {
        $password = 'incorrect';
    } elseif ($kind === 'inactive') {
        $farmer->update(['status' => false]);
    } elseif ($kind === 'deleted') {
        $farmer->delete();
    }

    $this->postJson('/api/account/login', ['login' => $login, 'password' => $password])->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Invalid credentials or inactive account.',
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(User::withTrashed()->findOrFail($farmer->id)->last_login_at)->toBeNull();
})->with([
    'unknown mobile' => ['unknown', 'mobile'], 'unknown email' => ['unknown', 'email'],
    'wrong mobile password' => ['wrong password', 'mobile'], 'wrong email password' => ['wrong password', 'email'],
    'inactive mobile' => ['inactive', 'mobile'], 'inactive email' => ['inactive', 'email'],
    'deleted mobile' => ['deleted', 'mobile'], 'deleted email' => ['deleted', 'email'],
]);

test('farmer login validates login password and optional device names using the existing API errors', function (array $override, string $field) {
    $response = $this->postJson('/api/account/login', array_replace(['login' => '9876543210', 'password' => 'password'], $override))
        ->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
        ->assertJsonValidationErrors($field);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'empty login' => [['login' => ''], 'login'], 'null login' => [['login' => null], 'login'],
    'numeric login' => [['login' => 9876543210], 'login'], 'array login' => [['login' => ['9876543210']], 'login'],
    'long login' => [['login' => str_repeat('a', 256)], 'login'],
    'invalid email' => [['login' => 'farmer@invalid'], 'login'],
    'short mobile' => [['login' => '987654321'], 'login'], 'long mobile' => [['login' => '98765432100'], 'login'],
    'wrong mobile prefix' => [['login' => '1234567890'], 'login'], 'country prefix' => [['login' => '+919876543210'], 'login'],
    'leading zeros' => [['login' => '00919876543210'], 'login'], 'internal spaces' => [['login' => '98765 43210'], 'login'],
    'letters' => [['login' => '987654321a'], 'login'],
    'empty password' => [['password' => ''], 'password'], 'null password' => [['password' => null], 'password'],
    'array password' => [['password' => ['password']], 'password'], 'long password' => [['password' => str_repeat('a', 256)], 'password'],
    'empty device' => [['device_name' => ''], 'device_name'], 'array device' => [['device_name' => ['phone']], 'device_name'],
    'long device' => [['device_name' => str_repeat('a', 256)], 'device_name'],
]);

test('login and password are required', function () {
    $this->post('/api/account/login', [])->assertUnprocessable()->assertJsonValidationErrors(['login', 'password']);
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('legacy credential fields cannot substitute for login', function (string $field) {
    $farmer = User::factory()->create();
    $this->post('/api/account/login', [$field => $farmer->{$field}, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('login');
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(['email', 'mobile']);

test('farmer and admin passwords remain isolated even when public account identifiers coincide', function () {
    $admin = AdminUser::factory()->create(['email' => 'shared@example.com', 'mobile' => '9876543210', 'password' => 'admin-password']);
    $farmer = User::factory()->create(['id' => $admin->id, 'email' => $admin->email, 'mobile' => $admin->mobile, 'password' => 'farmer-password']);

    $this->postJson('/api/account/login', ['login' => $farmer->mobile, 'password' => 'admin-password'])->assertUnauthorized();
    $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'farmer-password'])->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect($farmer->fresh()->last_login_at)->toBeNull();
    $this->postJson('/api/account/login', ['login' => $farmer->mobile, 'password' => 'farmer-password'])->assertOk()->assertJsonPath('data.user.id', $farmer->id);
    $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'admin-password'])->assertOk()->assertJsonPath('data.admin_user.id', $admin->id);
});

test('me resolves the authenticated farmer and ignores client supplied account ids', function () {
    $farmer = User::factory()->create(['last_name' => null]);
    $other = User::factory()->create();
    $this->withToken($farmer->createToken('test', ['frontend'])->plainTextToken)->getJson('/api/account/me?user_id='.$other->id.'&id='.$other->id)
        ->assertOk()->assertJsonPath('data.id', $farmer->id)->assertJsonPath('data.email', $farmer->email)
        ->assertJsonPath('data.last_name', null)->assertJsonPath('pagination', null);
    expect($farmer->fresh()->last_login_at)->toBeNull();
});

test('protected account endpoints return JSON 401 without authentication or an Accept header', function (string $method, string $path) {
    $this->call($method, $path)->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Unauthenticated.',
    ]);
})->with(['profile' => ['GET', '/api/account/me'], 'logout' => ['POST', '/api/account/logout']]);

test('an existing bearer token cannot access a newly suspended farmer', function (string $method, string $path) {
    $farmer = User::factory()->create();
    $token = $farmer->createToken('test', ['frontend'])->plainTextToken;
    $farmer->update(['status' => false]);

    $this->withToken($token)->json($method, $path)->assertForbidden()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'User account is inactive.',
    ]);
})->with(['profile' => ['GET', '/api/account/me'], 'logout' => ['POST', '/api/account/logout']]);

test('an existing bearer token cannot access a soft deleted farmer', function (string $method, string $path) {
    $farmer = User::factory()->create();
    $token = $farmer->createToken('test', ['frontend'])->plainTextToken;
    $farmer->delete();

    $this->withToken($token)->json($method, $path)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    $this->assertSoftDeleted($farmer);
})->with(['profile' => ['GET', '/api/account/me'], 'logout' => ['POST', '/api/account/logout']]);

test('admin tokens cannot revoke themselves through farmer logout even with matching ids and wildcard abilities', function () {
    $admin = AdminUser::factory()->create();
    User::factory()->create(['id' => $admin->id]);
    $token = $admin->createToken('test', ['*']);

    $this->withToken($token->plainTextToken)->postJson('/api/account/logout')->assertUnauthorized();
    $this->assertModelExists($token->accessToken);
});

test('farmer logout revokes only the current token and preserves other farmer and admin tokens', function () {
    $farmer = User::factory()->create(['last_login_at' => '2026-11-08 09:30:00']);
    $admin = AdminUser::factory()->create();
    $current = $farmer->createToken('current', ['frontend']);
    $other = $farmer->createToken('other-device', ['frontend']);
    $adminToken = $admin->createToken('admin', ['admin']);

    $this->withToken($current->plainTextToken)->postJson('/api/account/logout')->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null, 'message' => 'User logged out successfully.',
    ]);
    $this->assertModelMissing($current->accessToken);
    $this->assertModelExists($other->accessToken);
    $this->assertModelExists($adminToken->accessToken);
    expect($farmer->fresh()->last_login_at->toDateTimeString())->toBe('2026-11-08 09:30:00');

    app('auth')->forgetGuards();
    $this->withToken($current->plainTextToken)->getJson('/api/account/me')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->withToken($other->plainTextToken)->getJson('/api/account/me')->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($adminToken->plainTextToken)->getJson('/api/admin/me')->assertOk();
});

test('farmer token expiry is enforced by the user guard', function () {
    $farmer = User::factory()->create();
    $token = $farmer->createToken('expired', ['frontend'], now()->subMinute())->plainTextToken;
    $this->withToken($token)->getJson('/api/account/me')->assertUnauthorized();
});

test('login timestamp failures roll back token creation and preserve the previous login date', function () {
    $farmer = User::factory()->create(['last_login_at' => '2026-11-07 09:30:00']);
    Exceptions::fake();
    DB::unprepared("CREATE TRIGGER fail_farmer_login_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'Simulated login timestamp failure'); END");
    try {
        $response = $this->postJson('/api/account/login', ['login' => $farmer->mobile, 'password' => 'password'])->assertInternalServerError()
            ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
            ->assertJsonPath('message', 'Unable to log in. Please try again later.');
        expect($response->getContent())->not->toContain('Simulated login timestamp failure', 'SQLSTATE', 'password');
        $this->assertDatabaseCount('personal_access_tokens', 0);
        expect($farmer->fresh()->last_login_at->toDateTimeString())->toBe('2026-11-07 09:30:00');
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_farmer_login_update');
    }
});

test('farmer login throttles each identifier separately and normalizes email keys', function (string $login, string $sameLogin, string $otherLogin) {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/account/login', ['login' => $login, 'password' => 'invalid'])->assertUnauthorized();
    }
    $this->postJson('/api/account/login', ['login' => $sameLogin, 'password' => 'invalid'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('message', 'Too many requests. Please try again later.');
    $this->postJson('/api/account/login', ['login' => $otherLogin, 'password' => 'invalid'])->assertUnauthorized();
})->with([
    'mobile' => ['9876543210', '9876543210', '9876543211'],
    'email' => [' FARMER@EXAMPLE.COM ', 'farmer@example.com', 'other@example.com'],
]);

test('the shared login IP limit prevents bypassing limits with different mobile numbers', function () {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson('/api/account/login', ['login' => sprintf('9%09d', $attempt), 'password' => 'invalid'])->assertUnauthorized();
    }
    $this->postJson('/api/account/login', ['login' => '9999999999', 'password' => 'invalid'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('status', false);
});

test('invalid login formats are rejected before a user database lookup', function (string $login) {
    $farmer = User::factory()->create();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->postJson('/api/account/login', ['login' => $login, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors(['login' => 'The login field must be a valid email address or a 10-digit Indian mobile number.']);
        $userQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/from\\s+[`"]?users\\b/i', $query['query']) === 1);
        expect($userQueries)->toHaveCount(0);
    } finally {
        DB::disableQueryLog();
    }
    expect($farmer->fresh()->last_login_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with(["' OR 1=1 --", 'invalid-email@', '1234567890', '+919876543210']);

test('farmer authentication endpoints are confined to the account prefix', function (string $method, string $path) {
    $this->json($method, $path)->assertNotFound()->assertJsonPath('message', 'Resource not found.');
})->with(['login' => ['POST', '/api/login'], 'profile' => ['GET', '/api/me'], 'logout' => ['POST', '/api/logout']]);

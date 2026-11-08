<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\PersonalAccessToken;

uses(LazilyRefreshDatabase::class);

test('active farmers log in with their existing mobile format and receive a scoped expiring bearer token', function (string $mobile) {
    $this->travelTo('2026-11-08 12:00:00');
    $farmer = User::factory()->create(['first_name' => 'Hanuman', 'last_name' => 'Sharma', 'mobile' => $mobile]);
    $response = $this->postJson('/api/account/login', [
        'mobile' => $mobile, 'password' => 'password', 'device_name' => 'farmer-phone',
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
})->with(['local string' => '9876543210', 'leading zeros' => '00919876543210', 'country prefix' => '+919876543210']);

test('failed farmer logins share a generic 401 and do not issue tokens or update last login', function (string $kind) {
    $farmer = User::factory()->create();
    $mobile = $farmer->mobile;
    $password = 'password';
    if ($kind === 'unknown mobile') {
        $mobile = '0000000000';
    } elseif ($kind === 'wrong password') {
        $password = 'incorrect';
    } elseif ($kind === 'inactive') {
        $farmer->update(['status' => false]);
    } elseif ($kind === 'deleted') {
        $farmer->delete();
    }

    $this->postJson('/api/account/login', ['mobile' => $mobile, 'password' => $password])->assertUnauthorized()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Invalid credentials or inactive account.',
    ]);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(User::withTrashed()->findOrFail($farmer->id)->last_login_at)->toBeNull();
})->with(['unknown mobile', 'wrong password', 'inactive', 'deleted']);

test('farmer login validates mobile password and optional device names using nested API errors', function (array $override, string $field) {
    $response = $this->postJson('/api/account/login', array_replace(['mobile' => '9876543210', 'password' => 'password'], $override))
        ->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
        ->assertJsonValidationErrors($field);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('personal_access_tokens', 0);
})->with([
    'empty mobile' => [['mobile' => ''], 'mobile'], 'null mobile' => [['mobile' => null], 'mobile'],
    'numeric mobile' => [['mobile' => 9876543210], 'mobile'], 'array mobile' => [['mobile' => ['9876543210']], 'mobile'],
    'long mobile' => [['mobile' => str_repeat('9', 31)], 'mobile'],
    'empty password' => [['password' => ''], 'password'], 'null password' => [['password' => null], 'password'],
    'array password' => [['password' => ['password']], 'password'], 'long password' => [['password' => str_repeat('a', 256)], 'password'],
    'empty device' => [['device_name' => ''], 'device_name'], 'array device' => [['device_name' => ['phone']], 'device_name'],
    'long device' => [['device_name' => str_repeat('a', 256)], 'device_name'],
]);

test('email credentials cannot substitute for the required mobile login field', function () {
    $farmer = User::factory()->create();
    $this->post('/api/account/login', ['email' => $farmer->email, 'password' => 'password'])
        ->assertUnprocessable()->assertJsonValidationErrors('mobile');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('farmer and admin passwords remain isolated even when public account identifiers coincide', function () {
    $admin = AdminUser::factory()->create(['email' => 'shared@example.com', 'mobile' => '9876543210', 'password' => 'admin-password']);
    $farmer = User::factory()->create(['id' => $admin->id, 'email' => $admin->email, 'mobile' => $admin->mobile, 'password' => 'farmer-password']);

    $this->postJson('/api/account/login', ['mobile' => $farmer->mobile, 'password' => 'admin-password'])->assertUnauthorized();
    $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'farmer-password'])->assertUnauthorized();
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect($farmer->fresh()->last_login_at)->toBeNull();
    $this->postJson('/api/account/login', ['mobile' => $farmer->mobile, 'password' => 'farmer-password'])->assertOk()->assertJsonPath('data.user.id', $farmer->id);
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
        $response = $this->postJson('/api/account/login', ['mobile' => $farmer->mobile, 'password' => 'password'])->assertInternalServerError()
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

test('farmer login throttles each mobile separately and returns retry headers', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/account/login', ['mobile' => '9876543210', 'password' => 'invalid'])->assertUnauthorized();
    }
    $this->postJson('/api/account/login', ['mobile' => '9876543210', 'password' => 'invalid'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('message', 'Too many requests. Please try again later.');
    $this->postJson('/api/account/login', ['mobile' => '9876543211', 'password' => 'invalid'])->assertUnauthorized();
});

test('the shared login IP limit prevents bypassing limits with different mobile numbers', function () {
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson('/api/account/login', ['mobile' => sprintf('%010d', $attempt), 'password' => 'invalid'])->assertUnauthorized();
    }
    $this->postJson('/api/account/login', ['mobile' => '9999999999', 'password' => 'invalid'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('status', false);
});

test('mobile lookup binds input instead of interpreting SQL fragments', function () {
    $farmer = User::factory()->create();
    $this->postJson('/api/account/login', ['mobile' => "' OR 1=1 --", 'password' => 'password'])->assertUnauthorized();
    expect($farmer->fresh()->last_login_at)->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('farmer authentication endpoints are confined to the account prefix', function (string $method, string $path) {
    $this->json($method, $path)->assertNotFound()->assertJsonPath('message', 'Resource not found.');
})->with(['login' => ['POST', '/api/login'], 'profile' => ['GET', '/api/me'], 'logout' => ['POST', '/api/logout']]);

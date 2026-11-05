<?php

use App\Models\AdminUser;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

test('database list failures return a safe 500 response and are reported', function () {
    $actingAdmin = AdminUser::factory()->create();
    Sanctum::actingAs($actingAdmin, ['admin'], 'admin');
    Schema::drop('admin_users');
    Exceptions::fake();

    $response = $this->getJson('/api/admin/admin-users');

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null,
        'message' => 'Unable to retrieve admin users. Please try again later.',
        'pagination' => null,
    ]);
    Exceptions::assertReported(QueryException::class);
});

test('database creation failures return 500 and persist no admin', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER fail_admin_insert BEFORE INSERT ON admin_users BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', [
        'first_name' => 'New', 'last_name' => 'Admin', 'email' => 'new@example.com',
        'password' => 'secure-password', 'password_confirmation' => 'secure-password',
    ]);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('message', 'Unable to create the admin user. Please try again later.');
    $this->assertDatabaseCount('admin_users', 1);
    Exceptions::assertReported(QueryException::class);
});

test('a uniqueness conflict after creation validation returns 409 without creating an admin', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_admin_insert BEFORE INSERT ON admin_users BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: admin_users.email'); END");

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', [
        'first_name' => 'New', 'last_name' => 'Admin', 'email' => 'new@example.com',
        'password' => 'secure-password', 'password_confirmation' => 'secure-password',
    ]);

    $response->assertConflict()->assertExactJson([
        'status' => false, 'data' => null,
        'message' => 'A record with these details already exists.',
        'pagination' => null,
    ]);
    $this->assertDatabaseCount('admin_users', 1);
});

test('a uniqueness conflict after update validation returns 409 without changing the admin', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $originalEmail = $adminUser->email;
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    DB::unprepared("CREATE TRIGGER conflicting_admin_update BEFORE UPDATE OF email ON admin_users BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: admin_users.email'); END");

    $response = $this->withToken($token)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['email' => 'changed@example.com']);

    $response->assertConflict()->assertJsonPath('message', 'A record with these details already exists.');
    expect($adminUser->refresh()->email)->toBe($originalEmail);
});

test('failed token revocation rolls back admin updates and returns 500', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $adminToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);
    DB::unprepared("CREATE TRIGGER fail_token_delete BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($actingToken->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['status' => false]);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('message', 'Unable to update the admin user. Please try again later.');
    expect($adminUser->refresh()->status)->toBeTrue();
    $this->assertModelExists($adminToken->accessToken);
    Exceptions::assertReported(QueryException::class);
});

test('failed admin deletion restores already-deleted tokens and returns 500', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $adminToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);
    DB::unprepared("CREATE TRIGGER fail_admin_delete BEFORE DELETE ON admin_users BEGIN SELECT RAISE(ABORT, 'internal database failure'); END");
    Exceptions::fake();

    $response = $this->withToken($actingToken->plainTextToken)->deleteJson('/api/admin/admin-users/'.$adminUser->id);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('message', 'Unable to delete the admin user. Please try again later.');
    $this->assertModelExists($adminUser);
    $this->assertModelExists($adminToken->accessToken);
    Exceptions::assertReported(QueryException::class);
});

test('database failures during route binding return a safe 500 before the controller runs', function () {
    $actingAdmin = AdminUser::factory()->create();
    Sanctum::actingAs($actingAdmin, ['admin'], 'admin');
    Schema::drop('admin_users');
    Exceptions::fake();

    $response = $this->getJson('/api/admin/admin-users/'.$actingAdmin->id);

    $response->assertInternalServerError()->assertJsonPath('status', false)->assertJsonPath('message', 'Unable to complete the database operation. Please try again later.');
    Exceptions::assertReported(QueryException::class);
});

test('database failures during unique validation return a safe 500 before the controller runs', function () {
    $actingAdmin = AdminUser::factory()->create();
    Sanctum::actingAs($actingAdmin, ['admin'], 'admin');
    Schema::drop('admin_users');
    Exceptions::fake();

    $response = $this->postJson('/api/admin/admin-users', [
        'first_name' => 'New', 'last_name' => 'Admin', 'email' => 'new@example.com',
        'password' => 'secure-password', 'password_confirmation' => 'secure-password',
    ]);

    $response->assertInternalServerError()->assertJsonPath('message', 'Unable to complete the database operation. Please try again later.');
    $this->assertDatabaseCount('personal_access_tokens', 0);
    Exceptions::assertReported(QueryException::class);
});

test('an active admin can create an admin with safe defaults and a hashed password', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', [
        'first_name' => 'New',
        'last_name' => 'Admin',
        'mobile' => '0012345678',
        'email' => 'new@example.com',
        'password' => 'secure-password',
        'password_confirmation' => 'secure-password',
        'email_verified_at' => now()->toISOString(),
    ]);

    $response->assertCreated()->assertJsonPath('data.full_name', 'New Admin')->assertJsonPath('data.status', true)->assertJsonPath('data.is_super_user', false)->assertJsonPath('data.email_verified_at', null)->assertJsonPath('data.mobile', '0012345678')->assertJsonMissingPath('data.password');
    $this->assertDatabaseHas('admin_users', ['email' => 'new@example.com', 'status' => 1, 'is_super_user' => 0, 'email_verified_at' => null]);
    expect(Hash::check('secure-password', AdminUser::where('email', 'new@example.com')->first()->password))->toBeTrue();
});

test('the admin-management gate allows active admins regardless of their super-user flag', function () {
    $actingAdmin = AdminUser::factory()->superUser()->make();
    $regularAdmin = AdminUser::factory()->make();
    $inactiveSuperUser = AdminUser::factory()->superUser()->inactive()->make();
    $frontendUser = User::factory()->make();

    expect(Gate::forUser($actingAdmin)->allows('manage-admin-users'))->toBeTrue();
    expect(Gate::forUser($regularAdmin)->allows('manage-admin-users'))->toBeTrue();
    expect(Gate::forUser($inactiveSuperUser)->allows('manage-admin-users'))->toBeFalse();
    expect(Gate::forUser($frontendUser)->allows('manage-admin-users'))->toBeFalse();
});

test('an active admin can update the stored super-user flag without a super-user permission check', function () {
    $adminUser = AdminUser::factory()->create();
    $token = $adminUser->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['is_super_user' => true]);

    $response->assertOk()->assertJsonPath('status', true);
    expect($adminUser->refresh()->is_super_user)->toBeTrue();
});

test('frontend users cannot create admins even with wildcard token abilities and receive 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['*'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', ['is_super_user' => true]);

    $response->assertUnauthorized();
    $this->assertDatabaseCount('admin_users', 0);
});

test('admin lists use deterministic pagination and the standard envelope', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUsers = AdminUser::factory()->count(2)->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?per_page=2');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $adminUsers->last()->id)->assertJsonPath('pagination.current_page', 1)->assertJsonPath('pagination.per_page', 2)->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.last_page', 2)->assertJsonPath('pagination.from', 1)->assertJsonPath('pagination.to', 2)->assertJsonMissingPath('errors');
    expect($response->json('pagination.next_page_url'))->toContain('per_page=2');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $response->assertJsonMissingPath('data.data')->assertJsonMissingPath('data.0.password')->assertJsonMissingPath('data.0.remember_token');
});

test('keywords partially match admin names, full names, email, and mobile', function (string $keyword) {
    $actingAdmin = AdminUser::factory()->create([
        'first_name' => 'Owner', 'last_name' => 'Account', 'email' => 'owner@control.test', 'mobile' => null,
    ]);
    $adminUser = AdminUser::factory()->create([
        'first_name' => 'Jane', 'last_name' => 'Smith', 'email' => 'jane@example.com', 'mobile' => '0012345678',
    ]);
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.http_build_query(['keyword' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adminUser->id)->assertJsonPath('pagination.total', 1);
})->with([
    'first name' => ['jan'],
    'last name' => ['mith'],
    'partial full name' => ['ane Smi'],
    'email' => ['example.com'],
    'mobile' => ['12345'],
    'zero keyword' => ['0'],
    'surrounding whitespace' => ['  Jane Smith  '],
]);

test('status filters include only admins with the selected status', function (string $status) {
    $actingAdmin = AdminUser::factory()->create();
    $inactiveAdmin = AdminUser::factory()->inactive()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    $expectedAdmin = $status === '1' ? $actingAdmin : $inactiveAdmin;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.http_build_query(['status' => $status]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $expectedAdmin->id)->assertJsonPath('pagination.total', 1);
})->with(['active' => ['1'], 'inactive' => ['0']]);

test('keyword and status filters combine before pagination and remain in links', function (string $status) {
    $actingAdmin = AdminUser::factory()->create(['first_name' => 'Owner', 'last_name' => 'Account', 'email' => 'owner@control.test', 'mobile' => null]);
    AdminUser::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'status' => $status !== '1']);
    $adminUsers = AdminUser::factory()->count(2)->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'status' => $status === '1']);
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.http_build_query([
        'keyword' => 'Jane Smith', 'status' => $status, 'per_page' => 1,
    ]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adminUsers->last()->id)
        ->assertJsonPath('pagination.total', 2)->assertJsonPath('pagination.last_page', 2);
    parse_str(parse_url($response->json('pagination.next_page_url'), PHP_URL_QUERY), $nextPageQuery);
    expect($nextPageQuery)->toMatchArray(['keyword' => 'Jane Smith', 'status' => $status, 'per_page' => '1', 'page' => '2']);
})->with(['active' => ['1'], 'inactive' => ['0']]);

test('empty filters return both active and inactive admins', function () {
    $actingAdmin = AdminUser::factory()->create();
    AdminUser::factory()->inactive()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?keyword=%20%20&status=');

    $response->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('pagination.total', 2);
});

test('keyword searches treat special characters as literal text', function (string $keyword) {
    $actingAdmin = AdminUser::factory()->create(['first_name' => 'Owner', 'last_name' => 'Account']);
    $adminUser = AdminUser::factory()->create(['first_name' => $keyword, 'last_name' => 'Match']);
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.http_build_query(['keyword' => $keyword]));

    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $adminUser->id);
})->with(['percent' => ['%'], 'underscore' => ['_'], 'escape character' => ['!'], 'SQL fragment' => ["' OR 1=1 --"]]);

test('keywords with no matches return empty data and zero totals', function () {
    $actingAdmin = AdminUser::factory()->create(['first_name' => 'Owner', 'last_name' => 'Account']);
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?keyword=nonexistent-search-value');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.total', 0)
        ->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid admin filters receive 422 with the standard validation envelope', function (array $filters, string $field, string $message) {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.http_build_query($filters));

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'array keyword' => [['keyword' => ['Jane']], 'keyword', 'The keyword field must be a string.'],
    'oversized keyword' => [['keyword' => str_repeat('a', 512)], 'keyword', 'The keyword field must not be greater than 511 characters.'],
    'invalid status' => [['status' => 'active'], 'status', 'The status field must be true or false.'],
    'array status' => [['status' => ['1']], 'status', 'The status field must be true or false.'],
]);

test('out-of-range admin pages return empty data and nullable row bounds', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?page=10');

    $response->assertOk()->assertJsonPath('data', [])->assertJsonPath('pagination.from', null)->assertJsonPath('pagination.to', null);
});

test('invalid pagination receives 422', function (string $query, string $field) {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users?'.$query);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page' => ['page=0', 'page'],
    'noninteger page' => ['page=abc', 'page'],
    'zero page size' => ['per_page=0', 'per_page'],
    'oversized page' => ['per_page=101', 'per_page'],
]);

test('active admins can retrieve another admin without sensitive fields', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'created_at' => '2026-08-05 10:00:00',
    ]);
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users/'.$adminUser->id);

    $response->assertOk()->assertJsonPath('data.id', $adminUser->id)->assertJsonPath('data.full_name', 'Jane Smith')->assertJsonPath('data.added_at', '5 Aug 2026 10:00 AM')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    expect($adminUser->toArray()['full_name'])->toBe('Jane Smith');
});

test('missing admins return the standard 404 envelope', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->getJson('/api/admin/admin-users/999');

    $response->assertNotFound()->assertJsonPath('message', 'Resource not found.');
});

test('security changes revoke the affected admins tokens', function (array $changes) {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $adminToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($actingToken->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id, $changes);

    $response->assertOk();
    $this->assertModelMissing($adminToken->accessToken);
    $this->assertModelExists($actingToken->accessToken);
    $adminUser->refresh();
    foreach ($changes as $attribute => $value) {
        if ($attribute === 'password') {
            expect(Hash::check($value, $adminUser->password))->toBeTrue();
        } elseif ($attribute !== 'password_confirmation') {
            expect($adminUser->getAttribute($attribute))->toBe($value);
        }
    }
})->with([
    'password' => [['password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password']],
    'email' => [['email' => 'changed@example.com']],
    'deactivation' => [['status' => false]],
    'super-user flag' => [['is_super_user' => true]],
]);

test('profile changes preserve tokens and can retain the current unique email', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create(['first_name' => 'Jane', 'last_name' => 'Smith']);
    $adminToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($actingToken->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['first_name' => 'Changed', 'email' => $adminUser->email]);

    $response->assertOk()->assertJsonPath('data.first_name', 'Changed')->assertJsonPath('data.full_name', 'Changed Smith');
    $this->assertModelExists($adminToken->accessToken);
    expect($adminUser->refresh()->first_name)->toBe('Changed');
});

test('changing admin email clears its verification timestamp', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['email' => 'changed@example.com']);

    $response->assertOk()->assertJsonPath('data.email_verified_at', null);
    expect($adminUser->refresh()->email_verified_at)->toBeNull();
});

test('admin deletion revokes tokens and returns a 200 envelope', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $adminToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($actingToken->plainTextToken)->deleteJson('/api/admin/admin-users/'.$adminUser->id);

    $response->assertOk()->assertJsonPath('data', null)->assertJsonPath('status', true);
    $this->assertModelMissing($adminUser);
    $this->assertModelMissing($adminToken->accessToken);
});

test('missing admin creation fields return 422 and create no account', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', []);

    $response->assertUnprocessable()->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
    $this->assertDatabaseCount('admin_users', 1);
});

test('invalid admin creation fields return 422 and do not persist', function (array $changes, string $field) {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    $payload = array_replace([
        'first_name' => 'New', 'last_name' => 'Admin', 'email' => 'new@example.com',
        'password' => 'secure-password', 'password_confirmation' => 'secure-password',
    ], $changes);

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('admin_users', 1);
})->with([
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'mismatched password' => [['password_confirmation' => 'different'], 'password'],
    'invalid email' => [['email' => 'invalid'], 'email'],
    'oversized first name' => [['first_name' => str_repeat('a', 256)], 'first_name'],
    'oversized last name' => [['last_name' => str_repeat('a', 256)], 'last_name'],
    'oversized mobile' => [['mobile' => str_repeat('1', 31)], 'mobile'],
    'invalid status' => [['status' => 'active'], 'status'],
    'invalid super-user flag' => [['is_super_user' => 'yes'], 'is_super_user'],
]);

test('duplicate admin email is rejected on create with 422', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;
    $payload = ['first_name' => 'New', 'last_name' => 'Admin', 'email' => $actingAdmin->email, 'password' => 'secure-password', 'password_confirmation' => 'secure-password'];

    $response = $this->withToken($token)->postJson('/api/admin/admin-users', $payload);

    $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    $this->assertDatabaseCount('admin_users', 1);
});

test('duplicate admin email is rejected on update with 422 without changing the target', function () {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $originalEmail = $adminUser->email;
    $token = $actingAdmin->createToken('test', ['admin'])->plainTextToken;

    $response = $this->withToken($token)->patchJson('/api/admin/admin-users/'.$adminUser->id, ['email' => $actingAdmin->email]);

    $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'The email has already been taken.']);
    expect($adminUser->refresh()->email)->toBe($originalEmail);
});

test('explicit seeding creates a super user without overwriting an existing account', function () {
    config(['auth.bootstrap_admin' => ['first_name' => 'Initial', 'last_name' => 'Admin', 'email' => 'initial@example.com', 'password' => 'initial-secure-password']]);

    $this->seed(AdminUserSeeder::class);

    $adminUser = AdminUser::where('email', 'initial@example.com')->first();
    expect($adminUser->is_super_user)->toBeTrue();
    expect(Hash::check('initial-secure-password', $adminUser->password))->toBeTrue();
    $adminUser->update(['is_super_user' => false, 'first_name' => 'Existing']);

    $this->seed(AdminUserSeeder::class);

    expect($adminUser->refresh()->is_super_user)->toBeFalse();
    expect($adminUser->first_name)->toBe('Existing');
    $this->assertDatabaseCount('admin_users', 1);
});

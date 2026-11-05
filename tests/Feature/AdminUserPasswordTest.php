<?php

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

test('active admins can update another admins password without the old password and revoke only target tokens', function (bool $isSuperUser) {
    $actingAdmin = AdminUser::factory()->create(['is_super_user' => $isSuperUser]);
    $adminUser = AdminUser::factory()->create(['first_name' => 'Original', 'is_super_user' => false]);
    $originalEmail = $adminUser->email;
    $targetToken = $adminUser->createToken('first', ['admin']);
    $otherTargetToken = $adminUser->createToken('second', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($actingToken->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
        'first_name' => 'Untrusted', 'email' => 'untrusted@example.com', 'is_super_user' => true,
    ]);

    $response->assertOk()->assertJsonPath('status', true)->assertJsonPath('data.id', $adminUser->id)
        ->assertJsonPath('data.first_name', 'Original')->assertJsonPath('data.email', $originalEmail)
        ->assertJsonPath('data.is_super_user', false)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Admin user password updated successfully.')
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('errors');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    expect(Hash::check('new-secure-password', $adminUser->refresh()->password))->toBeTrue();
    $this->assertDatabaseHas('admin_users', ['id' => $adminUser->id, 'first_name' => 'Original', 'email' => $originalEmail, 'is_super_user' => false]);
    $this->assertModelMissing($targetToken->accessToken);
    $this->assertModelMissing($otherTargetToken->accessToken);
    $this->assertModelExists($actingToken->accessToken);
})->with(['regular admin' => [false], 'super user' => [true]]);

test('admins can update their own password without the old password and their current token is revoked', function () {
    $adminUser = AdminUser::factory()->create();
    $token = $adminUser->createToken('test', ['admin']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertOk()->assertJsonPath('data.id', $adminUser->id);
    expect(Hash::check('new-secure-password', $adminUser->refresh()->password))->toBeTrue();
    $this->assertModelMissing($token->accessToken);
});

test('invalid admin password updates return 422 and preserve the password and tokens', function (array $payload, string $message) {
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $token = $adminUser->createToken('test', ['admin']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', $payload);

    $response->assertUnprocessable()->assertJsonPath('status', false)->assertJsonPath('data', null)
        ->assertJsonPath('pagination', null)->assertJsonPath('message', 'Validation failed.')
        ->assertJsonValidationErrors(['password' => $message]);
    expect($adminUser->refresh()->password)->toBe($originalPassword);
    $this->assertModelExists($token->accessToken);
})->with([
    'missing password' => [[], 'The password field is required.'],
    'empty password' => [['password' => ''], 'The password field is required.'],
    'array password' => [['password' => ['new-secure-password']], 'The password field must be a string.'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'The password field must be at least 12 characters.'],
    'oversized password' => [['password' => str_repeat('a', 256), 'password_confirmation' => str_repeat('a', 256)], 'The password field must not be greater than 255 characters.'],
    'missing confirmation' => [['password' => 'new-secure-password'], 'The password field confirmation does not match.'],
    'mismatched confirmation' => [['password' => 'new-secure-password', 'password_confirmation' => 'different-password'], 'The password field confirmation does not match.'],
]);

test('admin password updates without a bearer token return 401', function () {
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;

    $response = $this->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertUnauthorized()->assertJsonPath('status', false)->assertJsonPath('message', 'Unauthenticated.');
    expect($adminUser->refresh()->password)->toBe($originalPassword);
});

test('frontend tokens cannot update admin passwords even with matching IDs and wildcard abilities and receive 401', function () {
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $user = User::factory()->create(['id' => $adminUser->id, 'email' => $adminUser->email]);
    $token = $user->createToken('test', ['*']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertUnauthorized();
    expect($adminUser->refresh()->password)->toBe($originalPassword);
    $this->assertModelExists($token->accessToken);
});

test('admin password updates without the admin token ability return 403', function () {
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $token = $adminUser->createToken('test', ['frontend']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertForbidden();
    expect($adminUser->refresh()->password)->toBe($originalPassword);
    $this->assertModelExists($token->accessToken);
});

test('inactive admins cannot update passwords and receive 403', function () {
    $actingAdmin = AdminUser::factory()->inactive()->create();
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $token = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertForbidden()->assertJsonPath('message', 'Admin account is inactive.');
    expect($adminUser->refresh()->password)->toBe($originalPassword);
});

test('admin password updates require the manage admin users gate and return 403 when denied', function () {
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $token = $adminUser->createToken('test', ['admin']);
    Gate::define('manage-admin-users', fn (AdminUser $user): bool => false);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertForbidden();
    expect($adminUser->refresh()->password)->toBe($originalPassword);
    $this->assertModelExists($token->accessToken);
});

test('password updates for missing admins return the standard 404 envelope', function () {
    $actingAdmin = AdminUser::factory()->create();
    $token = $actingAdmin->createToken('test', ['admin']);

    $response = $this->withToken($token->plainTextToken)->patchJson('/api/admin/admin-users/999/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertNotFound()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null, 'message' => 'Resource not found.',
    ]);
});

test('failed admin password updates return 500 and roll back password and token changes', function (string $failureSql) {
    $actingAdmin = AdminUser::factory()->create();
    $adminUser = AdminUser::factory()->create();
    $originalPassword = $adminUser->password;
    $targetToken = $adminUser->createToken('test', ['admin']);
    $actingToken = $actingAdmin->createToken('test', ['admin']);
    DB::unprepared($failureSql);
    Exceptions::fake();

    $response = $this->withToken($actingToken->plainTextToken)->patchJson('/api/admin/admin-users/'.$adminUser->id.'/password', [
        'password' => 'new-secure-password', 'password_confirmation' => 'new-secure-password',
    ]);

    $response->assertInternalServerError()->assertExactJson([
        'status' => false, 'data' => null, 'pagination' => null,
        'message' => 'Unable to update the admin user password. Please try again later.',
    ]);
    expect($adminUser->refresh()->password)->toBe($originalPassword);
    $this->assertModelExists($targetToken->accessToken);
    $this->assertModelExists($actingToken->accessToken);
    Exceptions::assertReported(QueryException::class);
})->with([
    'password save fails' => ["CREATE TRIGGER fail_admin_password_update BEFORE UPDATE OF password ON admin_users BEGIN SELECT RAISE(ABORT, 'internal database failure'); END"],
    'token revocation fails' => ["CREATE TRIGGER fail_admin_password_tokens BEFORE DELETE ON personal_access_tokens BEGIN SELECT RAISE(ABORT, 'internal database failure'); END"],
]);

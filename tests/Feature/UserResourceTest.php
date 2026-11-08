<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('user profiles expose mobile boolean status and the stored login timestamp', function () {
    $user = User::factory()->create();
    $user->fill(['first_name' => 'Hanuman', 'last_name' => 'Sharma', 'mobile' => '00919876543210', 'status' => true]);
    $user->last_login_at = '2026-11-08 09:30:00';
    $user->save();

    $this->withToken($user->createToken('test', ['frontend'])->plainTextToken)->getJson('/api/account/me')
        ->assertOk()
        ->assertJsonPath('data.first_name', 'Hanuman')
        ->assertJsonPath('data.last_name', 'Sharma')
        ->assertJsonMissingPath('data.name')
        ->assertJsonPath('data.mobile', '00919876543210')
        ->assertJsonPath('data.status', true)
        ->assertJsonPath('data.last_login_at', '2026-11-08T09:30:00.000000Z')
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'mobile' => '00919876543210', 'status' => true,
        'last_login_at' => '2026-11-08 09:30:00']);
});

test('multiple users may omit mobile and default to active without a recorded login', function () {
    $users = User::factory()->count(2)->create(['mobile' => null]);
    $user = $users->first();

    $this->withToken($user->createToken('test', ['frontend'])->plainTextToken)->getJson('/api/account/me')
        ->assertOk()->assertJsonPath('data.mobile', null)->assertJsonPath('data.status', true)->assertJsonPath('data.last_login_at', null);

    $this->assertDatabaseCount('users', 2);
    expect($user->status)->toBeTrue();
});

test('a mobile number cannot belong to multiple users', function () {
    User::factory()->create(['mobile' => '+919876543210']);

    expect(fn () => User::factory()->create(['mobile' => '+919876543210']))
        ->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('users', 1);
});

test('the migration preserves existing users and supplies safe defaults', function () {
    $migration = require database_path('migrations/2026_11_08_110755_add_mobile_status_and_last_login_at_to_users_table.php');
    $migration->down();
    try {
        $id = DB::table('users')->insertGetId([
            'first_name' => 'Existing', 'last_name' => 'User', 'email' => 'existing@example.com', 'password' => 'existing-hash',
        ]);
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => $id, 'first_name' => 'Existing', 'last_name' => 'User', 'email' => 'existing@example.com',
            'password' => 'existing-hash', 'mobile' => null, 'status' => true, 'last_login_at' => null]);
    } finally {
        if (! Schema::hasColumn('users', 'mobile')) {
            $migration->up();
        }
    }
});

<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

test('the name migration preserves existing names and account metadata', function () {
    $migration = require database_path('migrations/2026_11_08_111249_replace_name_with_first_name_and_last_name_in_users_table.php');
    $migration->down();
    try {
        $id = DB::table('users')->insertGetId([
            'name' => 'Existing Full Name', 'email' => 'legacy@example.com', 'password' => 'existing-hash',
            'mobile' => '+919876543210', 'status' => false, 'last_login_at' => '2026-11-08 09:30:00',
        ]);
        $migration->up();

        $this->assertDatabaseHas('users', ['id' => $id, 'first_name' => 'Existing Full Name', 'last_name' => null,
            'email' => 'legacy@example.com', 'password' => 'existing-hash', 'mobile' => '+919876543210',
            'status' => false, 'last_login_at' => '2026-11-08 09:30:00']);
        expect(Schema::hasColumn('users', 'name'))->toBeFalse();
    } finally {
        if (Schema::hasColumn('users', 'name')) {
            $migration->up();
        }
    }
});

test('rolling back names restores the original columns without updating first names', function (string $firstName, ?string $lastName, string $expected) {
    $user = User::factory()->create(['first_name' => $firstName, 'last_name' => $lastName]);
    $migration = require database_path('migrations/2026_11_08_111249_replace_name_with_first_name_and_last_name_in_users_table.php');

    try {
        $migration->down();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $expected, 'email' => $user->email]);
        expect(Schema::hasColumn('users', 'first_name'))->toBeFalse();
        expect(Schema::hasColumn('users', 'last_name'))->toBeFalse();
    } finally {
        if (Schema::hasColumn('users', 'name')) {
            $migration->up();
        }
    }
})->with([
    'both parts' => ['Hanuman', 'Sharma', 'Hanuman'],
    'mononym' => ['Hanuman', null, 'Hanuman'],
    'empty last name' => ['Hanuman', '', 'Hanuman'],
    'unicode' => ['हनुमान', 'शर्मा', 'हनुमान'],
    'maximum lengths' => [str_repeat('a', 255), str_repeat('b', 255), str_repeat('a', 255)],
]);

<?php

use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

uses(LazilyRefreshDatabase::class);

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function farmerRegistrationPayload(array $overrides = []): array
{
    return array_replace([
        'first_name' => 'Ramesh', 'last_name' => 'Kumar', 'mobile' => '9876543210', 'email' => 'ramesh@example.com',
        'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!', 'terms_accepted' => true,
    ], $overrides);
}

test('farmer registration creates normalized account data and one profile without automatic authentication', function () {
    $response = $this->postJson('/api/account/register', farmerRegistrationPayload([
        'first_name' => '  Ramesh   Chandra ', 'last_name' => ' Kumar  ', 'mobile' => ' 9876543210 ', 'email' => ' RAMESH@EXAMPLE.COM ',
        'status' => false, 'is_super_user' => true, 'email_verified_at' => '2000-01-01', 'last_login_at' => '2000-01-01',
        'preferred_language' => 'en', 'preferred_units' => 'acre', 'user_id' => 999,
    ]))->assertCreated()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('data.first_name', 'Ramesh Chandra')->assertJsonPath('data.last_name', 'Kumar')
        ->assertJsonPath('data.mobile', '9876543210')->assertJsonPath('data.email', 'ramesh@example.com')
        ->assertJsonPath('data.status', true)->assertJsonPath('data.email_verified_at', null)->assertJsonPath('data.last_login_at', null)
        ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
        ->assertJsonMissingPath('data.access_token')->assertJsonMissingPath('data.is_super_user')->assertJsonMissingPath('data.name');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $user = User::findOrFail($response->json('data.id'));
    expect(Hash::check('SecurePassword123!', $user->password))->toBeTrue();
    expect($user->password)->not->toBe('SecurePassword123!');
    $profile = $user->farmerProfile;
    expect($profile->user->is($user))->toBeTrue();
    expect($profile->preferred_language)->toBe('hi');
    expect($profile->preferred_units)->toBe('hectare');
    expect($profile->state_id)->toBeNull();
    expect($profile->district_id)->toBeNull();
    $this->assertDatabaseCount('farmer_profiles', 1);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    expect(Schema::hasColumn('users', 'country_code'))->toBeFalse();
    $this->getJson('/api/account/me')->assertUnauthorized();
    $this->postJson('/api/account/login', ['login' => '9876543210', 'password' => 'SecurePassword123!'])->assertOk();
});

test('multiple registrations may omit email or submit an empty optional email', function (mixed $email) {
    $payload = farmerRegistrationPayload();
    if ($email === 'omitted') {
        unset($payload['email']);
    } else {
        $payload['email'] = $email;
    }
    $first = $this->postJson('/api/account/register', $payload)->assertCreated()->assertJsonPath('data.email', null)->json('data.id');
    $payload['mobile'] = '9876543211';
    $second = $this->postJson('/api/account/register', $payload)->assertCreated()->assertJsonPath('data.email', null)->json('data.id');
    $this->assertDatabaseHas('users', ['id' => $first, 'email' => null]);
    $this->assertDatabaseHas('users', ['id' => $second, 'email' => null]);
    $this->assertDatabaseCount('farmer_profiles', 2);
})->with(['omitted', null, '', '   ']);

test('registration reports required fields before persistence', function () {
    $this->post('/api/account/register', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['first_name', 'last_name', 'mobile', 'password', 'password_confirmation', 'terms_accepted']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('farmer_profiles', 0);
});

test('invalid farmer registration fields fail with field level errors and no partial records', function (string $field, mixed $value, ?string $errorField = null) {
    $this->postJson('/api/account/register', farmerRegistrationPayload([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($errorField ?? $field);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('farmer_profiles', 0);
})->with([
    'blank first name' => ['first_name', '   '], 'first name type' => ['first_name', []], 'long first name' => ['first_name', str_repeat('a', 101)],
    'blank last name' => ['last_name', '  '], 'last name type' => ['last_name', []], 'long last name' => ['last_name', str_repeat('a', 101)],
    'mobile type' => ['mobile', 9876543210], 'mobile array' => ['mobile', []], 'mobile letters' => ['mobile', 'not-a-number'],
    'short mobile' => ['mobile', '987654321'], 'long mobile' => ['mobile', '98765432100'], 'invalid Indian prefix' => ['mobile', '1234567890'],
    'email format' => ['email', 'invalid'], 'email type' => ['email', []], 'long email' => ['email', str_repeat('a', 256).'@example.com'],
    'password type' => ['password', []], 'weak password' => ['password', 'short'], 'password length' => ['password', str_repeat('a', 256)],
    'confirmation missing' => ['password_confirmation', null], 'confirmation mismatch' => ['password_confirmation', 'OtherPassword123!', 'password'],
    'terms declined' => ['terms_accepted', false], 'terms invalid' => ['terms_accepted', 'no'],
]);

test('weak passwords are rejected even when their confirmation matches', function (string $password) {
    $this->postJson('/api/account/register', farmerRegistrationPayload(['password' => $password, 'password_confirmation' => $password]))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
})->with(['too short' => 'Abc1!', 'no uppercase' => 'securepassword123!', 'no lowercase' => 'SECUREPASSWORD123!',
    'no number' => 'SecurePassword!!!', 'no symbol' => 'SecurePassword123']);

test('registration rejects identifiers belonging to active or soft deleted farmers', function (string $field, bool $deleted) {
    $existing = User::factory()->create(['mobile' => '9876543210', 'email' => 'existing@example.com']);
    if ($deleted) {
        $existing->delete();
    }
    $override = $field === 'mobile' ? ['mobile' => $existing->mobile] : ['mobile' => '9876543211', 'email' => ' EXISTING@EXAMPLE.COM '];
    $this->postJson('/api/account/register', farmerRegistrationPayload($override))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseCount('farmer_profiles', 0);
})->with(['active mobile' => ['mobile', false], 'deleted mobile' => ['mobile', true],
    'active email' => ['email', false], 'deleted email' => ['email', true]]);

test('profile insertion failures roll back the user and all related registration writes', function () {
    Exceptions::fake();
    DB::unprepared("CREATE TRIGGER fail_registration_profile BEFORE INSERT ON farmer_profiles BEGIN SELECT RAISE(ABORT, 'Simulated profile failure'); END");
    try {
        $this->postJson('/api/account/register', farmerRegistrationPayload())->assertInternalServerError()->assertJsonPath('data', null);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('farmer_profiles', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_registration_profile');
    }
});

test('database uniqueness conflicts after validation return a safe 409', function () {
    DB::unprepared("CREATE TRIGGER conflict_registration_user BEFORE INSERT ON users BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: users.mobile'); END");
    try {
        $this->postJson('/api/account/register', farmerRegistrationPayload())->assertConflict()->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null, 'message' => 'A record with these details already exists.',
        ]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('farmer_profiles', 0);
    } finally {
        DB::unprepared('DROP TRIGGER conflict_registration_user');
    }
});

test('a farmer can have only one profile', function () {
    $profile = FarmerProfile::factory()->create();
    expect(fn () => $profile->user->farmerProfile()->create([]))->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('farmer_profiles', 1);
});

test('optional email changes retain database uniqueness for supplied identifiers', function (string $field) {
    $existing = User::factory()->create();
    expect(fn () => User::factory()->create([$field => $existing->{$field}]))->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('users', 1);
})->with(['email', 'mobile']);

test('rolling back optional email refuses to discard accounts without an email address', function () {
    $user = User::factory()->create(['email' => null]);
    $migration = require database_path('migrations/2026_11_08_121556_make_user_email_nullable.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot require user email while accounts without email addresses exist.');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => null]);
});

test('registration requests are throttled by client IP with the standard envelope', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/account/register', [])->assertUnprocessable();
    }
    $this->postJson('/api/account/register', [])->assertTooManyRequests()->assertHeader('Retry-After')
        ->assertJsonPath('status', false)->assertJsonPath('pagination', null);
});

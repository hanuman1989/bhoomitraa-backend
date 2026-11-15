<?php

use App\Models\AdminUser;
use App\Models\FarmerProfile;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

uses(LazilyRefreshDatabase::class);

/** @return array<string, mixed> */
function profilePersonalPayload(array $overrides = []): array
{
    return array_replace(['first_name' => 'Hanuman', 'last_name' => 'Yadav',
        'preferred_language' => 'hi', 'preferred_units' => 'bigha_pakka'], $overrides);
}

/** @return array{Region, Region} */
function profileRegions(): array
{
    $country = Region::factory()->create();
    $state = Region::factory()->under($country)->withEnglishName('Rajasthan')->create();
    $district = Region::factory()->under($state)->withEnglishName('Jaipur')->create();

    return [$state, $district];
}

/** @return array<string, mixed> */
function profileLocationPayload(Region $state, Region $district, array $overrides = []): array
{
    return array_replace(['state_id' => $state->id, 'district_id' => $district->id, 'village' => 'Rampura',
        'address' => 'House No. 12, Main Road', 'pincode' => '302001'], $overrides);
}

test('personal details save normalized Unicode names and preferences while preserving all read only fields', function () {
    $farmer = User::factory()->create(['remember_token' => 'remember-existing']);
    [$state, $district] = profileRegions();
    $profile = FarmerProfile::factory()->for($farmer)->create(['preferred_language' => 'en', 'preferred_units' => 'acre',
        'state_id' => $state->id, 'district_id' => $district->id, 'village' => 'Original village',
        'address' => 'Original address', 'pincode' => '302002', 'profile_image' => 'farmers/original.jpg']);
    $other = FarmerProfile::factory()->create();
    $beforeUser = $farmer->fresh()->getRawOriginal();
    $beforeProfile = $profile->fresh()->getRawOriginal();
    $otherBefore = $other->fresh()->getRawOriginal();

    $response = $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/personal', profilePersonalPayload([
            'first_name' => '  राम   कुमार  ', 'last_name' => ' यादव ',
            'user_id' => $other->user_id, 'id' => $other->id, 'email' => 'changed@example.com', 'mobile' => '9999999999',
            'password' => 'ChangedPassword123!', 'status' => false, 'email_verified_at' => '2000-01-01',
            'last_login_at' => '2000-01-01', 'remember_token' => 'changed', 'is_super_user' => true,
            'state_id' => null, 'district_id' => null, 'village' => 'Changed', 'profile_image' => 'changed.jpg',
            'farmer_profile' => ['user_id' => $other->user_id],
        ]))->assertOk()->assertJsonPath('status', true)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Personal details updated successfully.')
        ->assertJsonPath('data.id', $farmer->id)->assertJsonPath('data.first_name', 'राम कुमार')
        ->assertJsonPath('data.last_name', 'यादव')->assertJsonPath('data.email', $farmer->email)
        ->assertJsonPath('data.mobile', $farmer->mobile)->assertJsonPath('data.status', true)
        ->assertJsonPath('data.farmer_profile.id', $profile->id)->assertJsonPath('data.farmer_profile.preferred_language', 'hi')
        ->assertJsonPath('data.farmer_profile.preferred_units', 'bigha_pakka')
        ->assertJsonPath('data.farmer_profile.state.name', 'Rajasthan')
        ->assertJsonPath('data.farmer_profile.profile_image', 'farmers/original.jpg')
        ->assertJsonMissingPath('data.name')->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('data.access_token')
        ->assertJsonMissingPath('data.farmer_profile.user_id');

    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseHas('users', ['id' => $farmer->id, 'first_name' => 'राम कुमार', 'last_name' => 'यादव']);
    expect($farmer->fresh()->getRawOriginal())->toMatchArray(array_diff_key($beforeUser, array_flip(['first_name', 'last_name', 'updated_at'])));
    expect($profile->fresh()->getRawOriginal())->toMatchArray(array_diff_key($beforeProfile, array_flip(['preferred_language', 'preferred_units', 'updated_at'])));
    expect($other->fresh()->getRawOriginal())->toBe($otherBefore);
    $this->assertDatabaseCount('farmer_profiles', 2);
    $this->assertDatabaseCount('personal_access_tokens', 1);
});

test('personal details create a missing profile and later updates keep the same profile', function (string $unit) {
    $farmer = User::factory()->create(['email' => null]);
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/personal', profilePersonalPayload(['preferred_units' => $unit]))
        ->assertOk()->assertJsonPath('data.email', null)->assertJsonPath('data.farmer_profile.preferred_units', $unit)
        ->assertJsonPath('data.farmer_profile.state', null)->assertJsonPath('data.farmer_profile.district', null);
    $profileId = $farmer->farmerProfile()->sole()->id;

    $this->patchJson('/api/account/profile/personal', profilePersonalPayload(['preferred_language' => 'en', 'preferred_units' => $unit]))
        ->assertOk()->assertJsonPath('data.farmer_profile.id', $profileId)
        ->assertJsonPath('data.farmer_profile.preferred_language', 'en');
    $this->assertDatabaseHas('farmer_profiles', ['id' => $profileId, 'user_id' => $farmer->id, 'preferred_language' => 'en', 'preferred_units' => $unit]);
    $this->assertDatabaseCount('farmer_profiles', 1);
})->with(['bigha_kaccha', 'bigha_pakka', 'hectare', 'acre', 'sqm', 'sqft', 'sqyd', 'guntha']);

test('profile steps reject missing required fields with the standard 422 field errors', function (string $step, array $fields) {
    $farmer = User::factory()->create();
    $response = $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patch('/api/account/profile/'.$step, [])->assertUnprocessable()->assertJsonValidationErrors($fields)
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null)
        ->assertJsonPath('message', 'Validation failed.');
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message', 'errors']);
    $this->assertDatabaseCount('farmer_profiles', 0);
})->with([
    'personal' => ['personal', ['first_name', 'last_name', 'preferred_language', 'preferred_units']],
    'location' => ['location', ['state_id', 'district_id', 'village']],
]);

test('invalid personal details return 422 and preserve both records', function (string $field, mixed $value, string $message) {
    $farmer = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create();
    $beforeUser = $farmer->fresh()->getRawOriginal();
    $beforeProfile = $profile->fresh()->getRawOriginal();
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/personal', profilePersonalPayload([$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);
    expect($farmer->fresh()->getRawOriginal())->toBe($beforeUser);
    expect($profile->fresh()->getRawOriginal())->toBe($beforeProfile);
})->with([
    'blank first name' => ['first_name', '   ', 'The first name field is required.'],
    'Unicode whitespace first name' => ['first_name', "\u{2003}\u{00a0}", 'The first name field is required.'],
    'first name type' => ['first_name', [], 'The first name field is required.'],
    'first name length' => ['first_name', str_repeat('a', 101), 'The first name field must not be greater than 100 characters.'],
    'blank last name' => ['last_name', '   ', 'The last name field is required.'],
    'last name type' => ['last_name', 123, 'The last name field must be a string.'],
    'last name length' => ['last_name', str_repeat('a', 101), 'The last name field must not be greater than 100 characters.'],
    'unsupported language' => ['preferred_language', 'fr', 'The selected preferred language is invalid.'],
    'language type' => ['preferred_language', ['hi'], 'The preferred language field must be a string.'],
    'unsupported units' => ['preferred_units', 'square_meter', 'The selected preferred units is invalid.'],
    'non area units' => ['preferred_units', 'kg', 'The selected preferred units is invalid.'],
    'ambiguous Bigha' => ['preferred_units', 'bigha', 'The selected preferred units is invalid.'],
    'legacy hectare' => ['preferred_units', 'ha', 'The selected preferred units is invalid.'],
    'unverified metric' => ['preferred_units', 'metric', 'The selected preferred units is invalid.'],
    'unverified imperial' => ['preferred_units', 'imperial', 'The selected preferred units is invalid.'],
    'units type' => ['preferred_units', ['bigha'], 'The preferred units field must be a string.'],
]);

test('personal details roll back names when profile persistence fails with a safe 500', function (bool $existingProfile) {
    $farmer = User::factory()->create();
    if ($existingProfile) {
        FarmerProfile::factory()->for($farmer)->create(['preferred_language' => 'en']);
    }
    $before = $farmer->fresh()->getRawOriginal();
    $operation = $existingProfile ? 'UPDATE' : 'INSERT';
    DB::unprepared('CREATE TRIGGER fail_personal_profile BEFORE '.$operation." ON farmer_profiles BEGIN SELECT RAISE(ABORT, 'forced profile failure'); END");
    Exceptions::fake();
    try {
        $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
            ->patchJson('/api/account/profile/personal', profilePersonalPayload())->assertStatus(500)->assertExactJson([
                'status' => false, 'data' => null, 'pagination' => null,
                'message' => 'Unable to update personal details. Please try again later.',
            ]);
        expect($farmer->fresh()->getRawOriginal())->toBe($before);
        $this->assertDatabaseCount('farmer_profiles', $existingProfile ? 1 : 0);
        if ($existingProfile) {
            $this->assertDatabaseHas('farmer_profiles', ['user_id' => $farmer->id, 'preferred_language' => 'en']);
        }
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_personal_profile');
    }
})->with(['missing profile' => false, 'existing profile' => true]);

test('location details save only the authenticated farmers location and return translated region names', function () {
    [$state, $district] = profileRegions();
    $state->translations()->create(['locale' => 'hi', 'name' => 'राजस्थान']);
    $farmer = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create(['preferred_language' => 'en', 'preferred_units' => 'acre', 'profile_image' => 'farmer.jpg']);
    $other = FarmerProfile::factory()->create();
    $beforeUser = $farmer->fresh()->getRawOriginal();
    $otherBefore = $other->fresh()->getRawOriginal();

    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)->withHeader('X-Locale', 'hi')
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district, [
            'village' => '  राम   पुरा  ', 'address' => "  मकान नं. 12\nMain Road  ", 'pincode' => ' 302001 ',
            'user_id' => $other->user_id, 'id' => $other->id, 'first_name' => 'Changed', 'last_name' => 'Changed',
            'preferred_language' => 'hi', 'preferred_units' => 'bigha', 'profile_image' => 'changed.jpg',
            'email' => 'changed@example.com', 'mobile' => '9999999999', 'password' => 'changed', 'status' => false,
        ]))->assertOk()->assertJsonPath('message', 'Farmer profile updated successfully.')
        ->assertJsonPath('data.id', $farmer->id)->assertJsonPath('data.farmer_profile.id', $profile->id)
        ->assertJsonPath('data.farmer_profile.state_id', $state->id)->assertJsonPath('data.farmer_profile.district_id', $district->id)
        ->assertJsonPath('data.farmer_profile.state', ['id' => $state->id, 'name' => 'राजस्थान'])
        ->assertJsonPath('data.farmer_profile.district', ['id' => $district->id, 'name' => 'Jaipur'])
        ->assertJsonPath('data.farmer_profile.village', 'राम पुरा')
        ->assertJsonPath('data.farmer_profile.address', "मकान नं. 12\nMain Road")
        ->assertJsonPath('data.farmer_profile.pincode', '302001')
        ->assertJsonPath('data.farmer_profile.preferred_language', 'en')->assertJsonPath('data.farmer_profile.preferred_units', 'acre')
        ->assertJsonPath('data.farmer_profile.profile_image', 'farmer.jpg');

    $this->assertDatabaseHas('farmer_profiles', ['id' => $profile->id, 'state_id' => $state->id, 'district_id' => $district->id,
        'village' => 'राम पुरा', 'address' => "मकान नं. 12\nMain Road", 'pincode' => '302001']);
    expect($farmer->fresh()->getRawOriginal())->toBe($beforeUser);
    expect($other->fresh()->getRawOriginal())->toBe($otherBefore);
    $this->assertDatabaseCount('farmer_profiles', 2);
});

test('location details create a missing profile with defaults and preserve omitted optional fields on subsequent saves', function () {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district))
        ->assertOk()->assertJsonPath('data.farmer_profile.preferred_language', 'hi')
        ->assertJsonPath('data.farmer_profile.preferred_units', 'hectare');
    $profileId = $farmer->farmerProfile()->sole()->id;
    $this->patchJson('/api/account/profile/location', ['state_id' => $state->id, 'district_id' => $district->id, 'village' => 'New village'])
        ->assertOk()->assertJsonPath('data.farmer_profile.id', $profileId)
        ->assertJsonPath('data.farmer_profile.address', 'House No. 12, Main Road')
        ->assertJsonPath('data.farmer_profile.pincode', '302001');
    $this->assertDatabaseHas('farmer_profiles', ['id' => $profileId, 'user_id' => $farmer->id, 'village' => 'New village',
        'address' => 'House No. 12, Main Road', 'pincode' => '302001']);
    $this->assertDatabaseCount('farmer_profiles', 1);
});

test('empty optional location fields clear to null', function (mixed $value) {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    FarmerProfile::factory()->for($farmer)->create(['address' => 'Old address', 'pincode' => '302001']);
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district, ['address' => $value, 'pincode' => $value]))
        ->assertOk()->assertJsonPath('data.farmer_profile.address', null)->assertJsonPath('data.farmer_profile.pincode', null);
    $this->assertDatabaseHas('farmer_profiles', ['user_id' => $farmer->id, 'address' => null, 'pincode' => null]);
})->with([null, '', '   ', "\u{2003}\u{00a0}"]);

test('invalid location fields return 422 without changing the saved profile', function (string $field, mixed $value, string $message) {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create(['village' => 'Old village']);
    $before = $profile->fresh()->getRawOriginal();
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district, [$field => $value]))
        ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);
    expect($profile->fresh()->getRawOriginal())->toBe($before);
})->with([
    'state type' => ['state_id', [], 'The state id field is required.'],
    'state positive' => ['state_id', 0, 'The state id field must be at least 1.'],
    'state integer' => ['state_id', 1.5, 'The state id field must be an integer.'],
    'district type' => ['district_id', 'wrong', 'The district id field must be an integer.'],
    'district positive' => ['district_id', -1, 'The district id field must be at least 1.'],
    'village whitespace' => ['village', '   ', 'The village field is required.'],
    'village type' => ['village', 123, 'The village field must be a string.'],
    'village length' => ['village', str_repeat('a', 151), 'The village field must not be greater than 150 characters.'],
    'address type' => ['address', ['wrong'], 'The address field must be a string.'],
    'address length' => ['address', str_repeat('a', 501), 'The address field must not be greater than 500 characters.'],
    'PIN type' => ['pincode', 302001, 'The pincode field must be a string.'],
    'short PIN' => ['pincode', '30200', 'The pincode field format is invalid.'],
    'long PIN' => ['pincode', '3020011', 'The pincode field format is invalid.'],
    'leading zero PIN' => ['pincode', '002001', 'The pincode field format is invalid.'],
    'alphabetic PIN' => ['pincode', '30200a', 'The pincode field format is invalid.'],
    'Unicode digits PIN' => ['pincode', '३०२००१', 'The pincode field format is invalid.'],
    'PIN newline' => ['pincode', "302\n001", 'The pincode field format is invalid.'],
]);

test('location validation rejects missing wrong type mismatched and newly inactive regions', function (string $kind, string $field, string $message) {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $payload = profileLocationPayload($state, $district);
    if ($kind === 'missing') {
        $payload[$field] = 999999;
    } elseif ($kind === 'wrong type') {
        $payload[$field] = $field === 'state_id' ? $district->id : $state->id;
    } elseif ($kind === 'mismatch') {
        $otherState = Region::factory()->under($state->parent)->create();
        $payload['district_id'] = Region::factory()->under($otherState)->create()->id;
    } else {
        ($field === 'state_id' ? $state : $district)->update(['is_active' => false]);
    }
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', $payload)->assertUnprocessable()
        ->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);
    $this->assertDatabaseCount('farmer_profiles', 0);
})->with([
    'missing state' => ['missing', 'state_id', 'Select an existing state.'],
    'missing district' => ['missing', 'district_id', 'Select an existing district.'],
    'district as state' => ['wrong type', 'state_id', 'Select an existing state.'],
    'state as district' => ['wrong type', 'district_id', 'Select an existing district.'],
    'wrong parent' => ['mismatch', 'district_id', 'The district must belong to the selected state.'],
    'inactive state' => ['inactive', 'state_id', 'Inactive states cannot be newly selected.'],
    'inactive district' => ['inactive', 'district_id', 'Inactive districts cannot be newly selected.'],
]);

test('saved inactive regions may be retained while other location details change', function () {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $profile = FarmerProfile::factory()->for($farmer)->create(['state_id' => $state->id, 'district_id' => $district->id]);
    $state->update(['is_active' => false]);
    $district->update(['is_active' => false]);
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district))->assertOk();
    $this->assertDatabaseHas('farmer_profiles', ['id' => $profile->id, 'state_id' => $state->id, 'district_id' => $district->id, 'village' => 'Rampura']);
});

test('region hierarchy is checked again inside the location transaction', function () {
    [$state, $district] = profileRegions();
    $otherState = Region::factory()->under($state->parent)->create();
    $farmer = User::factory()->create();
    $changed = false;
    DB::listen(function (QueryExecuted $query) use (&$changed, $district, $otherState): void {
        if (! $changed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "regions"')) {
            $changed = true;
            $district->update(['parent_id' => $otherState->id]);
        }
    });
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/location', profileLocationPayload($state, $district))
        ->assertUnprocessable()->assertJsonValidationErrors('district_id');
    expect($changed)->toBeTrue();
    $this->assertDatabaseCount('farmer_profiles', 0);
});

test('each wizard step commits independently and me returns current persisted profile values', function () {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $token = $farmer->createToken('profile', ['frontend'])->plainTextToken;
    $this->withToken($token)->patchJson('/api/account/profile/personal', profilePersonalPayload())->assertOk();
    $this->assertDatabaseHas('users', ['id' => $farmer->id, 'first_name' => 'Hanuman', 'last_name' => 'Yadav']);
    $this->assertDatabaseHas('farmer_profiles', ['user_id' => $farmer->id, 'preferred_language' => 'hi', 'preferred_units' => 'bigha_pakka', 'state_id' => null]);

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/account/me')->assertOk()
        ->assertJsonPath('data.first_name', 'Hanuman')->assertJsonPath('data.last_name', 'Yadav')
        ->assertJsonPath('data.farmer_profile.preferred_units', 'bigha_pakka');
    $this->patchJson('/api/account/profile/location', profileLocationPayload($state, $district, ['pincode' => 'bad']))
        ->assertUnprocessable()->assertJsonValidationErrors('pincode');
    $this->assertDatabaseHas('users', ['id' => $farmer->id, 'first_name' => 'Hanuman', 'last_name' => 'Yadav']);
    $this->assertDatabaseHas('farmer_profiles', ['user_id' => $farmer->id, 'preferred_language' => 'hi', 'state_id' => null]);
    $updated = $this->patchJson('/api/account/profile/location', profileLocationPayload($state, $district))->assertOk()->json('data');

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/account/me')->assertOk()->assertJsonPath('data', $updated);
    $this->assertDatabaseCount('farmer_profiles', 1);
});

test('a failed location write returns a safe 500 without losing already committed personal details', function () {
    [$state, $district] = profileRegions();
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->patchJson('/api/account/profile/personal', profilePersonalPayload())->assertOk();
    DB::unprepared("CREATE TRIGGER fail_location_profile BEFORE UPDATE ON farmer_profiles BEGIN SELECT RAISE(ABORT, 'forced location failure'); END");
    Exceptions::fake();
    try {
        $this->patchJson('/api/account/profile/location', profileLocationPayload($state, $district))->assertStatus(500)->assertExactJson([
            'status' => false, 'data' => null, 'pagination' => null,
            'message' => 'Unable to update location details. Please try again later.',
        ]);
        $this->assertDatabaseHas('users', ['id' => $farmer->id, 'first_name' => 'Hanuman', 'last_name' => 'Yadav']);
        $this->assertDatabaseHas('farmer_profiles', ['user_id' => $farmer->id, 'preferred_language' => 'hi', 'state_id' => null, 'district_id' => null]);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_location_profile');
    }
});

test('me returns a null profile without creating a database record when a profile is missing', function () {
    $farmer = User::factory()->create();
    $this->withToken($farmer->createToken('profile', ['frontend'])->plainTextToken)
        ->getJson('/api/account/me')->assertOk()->assertJsonPath('data.farmer_profile', null);
    $this->assertDatabaseCount('farmer_profiles', 0);
});

test('both profile steps enforce bearer authentication frontend abilities active status and model separation', function (string $step, string $identity, int $status) {
    $farmer = User::factory()->create();
    $token = null;
    if ($identity === 'admin') {
        $admin = AdminUser::factory()->create(['id' => $farmer->id, 'email' => $farmer->email]);
        $token = $admin->createToken('admin', ['admin', 'frontend'])->plainTextToken;
    } elseif ($identity !== 'guest') {
        $token = $farmer->createToken('profile', $identity === 'wrong ability' ? ['admin'] : ['frontend'],
            $identity === 'expired' ? now()->subMinute() : now()->addHour())->plainTextToken;
        if ($identity === 'inactive') {
            $farmer->update(['status' => false]);
        } elseif ($identity === 'deleted') {
            $farmer->delete();
        } elseif ($identity === 'revoked') {
            $farmer->tokens()->delete();
        }
    }
    if ($token !== null) {
        $this->withToken($token);
    }
    $response = $this->patch('/api/account/profile/'.$step, [])->assertStatus($status)
        ->assertJsonPath('status', false)->assertJsonPath('data', null)->assertJsonPath('pagination', null);
    expect(array_keys($response->json()))->toBe(['status', 'data', 'pagination', 'message']);
    $this->assertDatabaseCount('farmer_profiles', 0);
})->with(['personal', 'location'])->with([
    'guest' => ['guest', 401], 'admin' => ['admin', 401], 'wrong ability' => ['wrong ability', 403],
    'inactive' => ['inactive', 403], 'deleted' => ['deleted', 401], 'expired' => ['expired', 401], 'revoked' => ['revoked', 401],
]);

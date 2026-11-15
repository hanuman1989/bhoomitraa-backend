<?php

use App\Models\AdminUser;
use App\Models\User;
use App\Notifications\FarmerResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword as DefaultResetPassword;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    config(['app.url' => 'https://farmer.example.test']);
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function farmerPasswordResetPayload(User $user, ?string $token = null, array $overrides = []): array
{
    return array_replace([
        'email' => $user->email, 'token' => $token ?? Password::broker('users')->createToken($user),
        'password' => 'NewSecurePassword123!', 'password_confirmation' => 'NewSecurePassword123!',
    ], $overrides);
}

test('forgot password sends a Laravel managed farmer reset token and a generic response', function () {
    $user = User::factory()->create(['email' => 'farmer@example.com']);
    Notification::fake();

    $response = $this->postJson('/api/account/forgot-password', ['email' => ' FARMER@EXAMPLE.COM '])->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null,
        'message' => 'If an account exists with this email, a password reset link has been sent.',
    ]);
    Notification::assertSentTo($user, FarmerResetPassword::class, function (FarmerResetPassword $notification) use ($user): bool {
        expect(Password::broker('users')->tokenExists($user, $notification->token))->toBeTrue();
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->value('token'))->not->toBe($notification->token);

        return true;
    });
    expect($response->getContent())->not->toContain('farmer@example.com', 'access_token', 'token');
    $this->assertDatabaseCount('personal_access_tokens', 0);
});

test('unknown deleted admin only and email less accounts get indistinguishable forgot password responses', function (string $kind) {
    $email = 'recovery@example.com';
    if ($kind === 'deleted') {
        User::factory()->create(['email' => $email])->delete();
    } elseif ($kind === 'admin only') {
        AdminUser::factory()->create(['email' => $email]);
    } elseif ($kind === 'without email') {
        User::factory()->create(['email' => null]);
    }
    Notification::fake();
    $this->postJson('/api/account/forgot-password', ['email' => $email])->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null,
        'message' => 'If an account exists with this email, a password reset link has been sent.',
    ]);
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->assertDatabaseCount('admin_user_password_reset_tokens', 0);
})->with(['unknown', 'deleted', 'admin only', 'without email']);

test('forgot password validates email without requiring authentication', function (array $payload) {
    $this->postJson('/api/account/forgot-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
})->with(['missing' => [[]], 'null' => [['email' => null]], 'empty' => [['email' => '']],
    'format' => [['email' => 'not-an-email']], 'array' => [['email' => []]], 'length' => [['email' => str_repeat('a', 256).'@example.com']]]);

test('the branded green reset button uses APP_URL and safely encoded token and email query parameters', function () {
    config(['app.url' => 'https://portal.example.test/farmer/', 'app.frontend_url' => 'https://ignored.example.test',
        'app.name' => 'Laravel', 'auth.defaults.passwords' => 'admin_users', 'auth.passwords.users.expire' => 45,
        'auth.passwords.admin_users.expire' => 10]);
    $user = User::factory()->create(['email' => 'farmer+reset@example.com']);
    $message = (new FarmerResetPassword('sample token+value&'))->toMail($user);
    expect($message->subject)->toBe('Krishi AI — Reset Your Password');
    expect($message->data()['resetUrl'])->toBe('https://portal.example.test/farmer/account/reset-password?token=sample%20token%2Bvalue%26&email=farmer%2Breset%40example.com');
    $html = (string) $message->render();
    expect($html)->toContain('Reset Password', 'button-success', 'Krishi AI', '45 minutes', 'ignore this email')
        ->not->toContain('Laravel', 'ignored.example.test');
    expect(html_entity_decode($html))->toContain($message->data()['resetUrl']);
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());
    expect($text)->toContain('Krishi AI', 'Reset Password', $message->data()['resetUrl'], '45 minutes');
});

test('a custom admin reset URL remains independent from the farmer notification', function () {
    $admin = AdminUser::factory()->create();
    $user = User::factory()->create();
    $callback = DefaultResetPassword::$createUrlCallback;
    try {
        DefaultResetPassword::createUrlUsing(fn (mixed $notifiable, string $token): string => 'https://admin.example.test/reset?token='.$token);
        expect((new DefaultResetPassword('admin-token'))->toMail($admin)->actionUrl)->toBe('https://admin.example.test/reset?token=admin-token');
        expect((new FarmerResetPassword('farmer-token'))->toMail($user)->data()['resetUrl'])
            ->toContain('https://farmer.example.test/account/reset-password?token=farmer-token');
    } finally {
        DefaultResetPassword::$createUrlCallback = $callback;
    }
});

test('invalid APP_URL disables recovery uniformly before account lookup or notification', function (mixed $url) {
    $user = User::factory()->create();
    config(['app.url' => $url]);
    Notification::fake();
    foreach ([$user->email, 'unknown@example.com'] as $email) {
        $this->postJson('/api/account/forgot-password', ['email' => $email])->assertStatus(503)->assertJsonPath('status', false);
    }
    Notification::assertNothingSent();
})->with(['missing' => [null], 'unsafe scheme' => ['javascript:alert(1)'], 'credentials' => ['https://user:secret@example.com'],
    'query' => ['https://example.com?redirect=elsewhere'], 'fragment' => ['https://example.com/#fragment']]);

test('broker throttling suppresses repeated mail without revealing account existence', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    Notification::fake();
    $first = $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk()->json();
    $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk()->assertExactJson($first);
    Notification::assertSentToTimes($user, FarmerResetPassword::class, 1);
    $this->travel(61)->seconds();
    $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk();
    Notification::assertSentToTimes($user, FarmerResetPassword::class, 2);
});

test('forgot password endpoint rate limits preserve retry headers and generic unknown account handling', function () {
    Notification::fake();
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/account/forgot-password', ['email' => 'unknown@example.com'])->assertOk();
    }
    $this->postJson('/api/account/forgot-password', ['email' => 'unknown@example.com'])
        ->assertTooManyRequests()->assertHeader('Retry-After')->assertJsonPath('status', false);
    Notification::assertNothingSent();
});

test('mail delivery failures omit transport credentials and leave no unusable reset token or throttle', function () {
    $user = User::factory()->create();
    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('send')->once()->andThrow(new TransportException('secret-smtp-credentials'));
    Mail::shouldReceive('mailer')->once()->with(null)->andReturn($mailer);
    Log::shouldReceive('error')->once()->with('Unable to send the farmer password reset email.', ['exception_type' => TransportException::class]);

    $response = $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk()->assertJsonPath('status', true);
    expect($response->getContent())->not->toContain('secret-smtp-credentials');
    $this->assertDatabaseCount('password_reset_tokens', 0);
    Notification::fake();
    $this->postJson('/api/account/forgot-password', ['email' => $user->email])->assertOk();
    Notification::assertSentToTimes($user, FarmerResetPassword::class, 1);
});

test('a valid farmer reset changes the hashed password rotates remember token and revokes only that farmers tokens', function () {
    $this->freezeTime();
    $user = User::factory()->create(['remember_token' => 'previous-remember-token']);
    $other = User::factory()->create();
    $admin = AdminUser::factory()->create(['id' => $user->id, 'email' => $user->email]);
    $token = $user->createToken('current', ['frontend']);
    $otherUserToken = $other->createToken('other', ['frontend']);
    $adminToken = $admin->createToken('admin', ['admin']);
    $adminPassword = $admin->password;
    $payload = farmerPasswordResetPayload($user);
    Event::fake([PasswordReset::class]);

    $response = $this->postJson('/api/account/reset-password', $payload)->assertOk()->assertExactJson([
        'status' => true, 'data' => null, 'pagination' => null,
        'message' => 'Password reset successfully. Please login with your new password.',
    ]);
    expect($response->getContent())->not->toContain($payload['token'], 'NewSecurePassword123!');
    $user->refresh();
    expect(Hash::check('NewSecurePassword123!', $user->password))->toBeTrue();
    expect($user->remember_token)->not->toBe('previous-remember-token');
    expect($user->last_login_at)->toBeNull();
    $this->assertModelMissing($token->accessToken);
    $this->assertModelExists($otherUserToken->accessToken);
    $this->assertModelExists($adminToken->accessToken);
    expect($admin->fresh()->password)->toBe($adminPassword);
    expect(Password::broker('users')->tokenExists($user, $payload['token']))->toBeFalse();
    Event::assertDispatchedTimes(PasswordReset::class, 1);
    $this->getJson('/api/account/me')->assertUnauthorized();
    $this->withToken($token->plainTextToken)->getJson('/api/account/me')->assertUnauthorized();
    $this->postJson('/api/account/login', ['login' => $user->mobile, 'password' => 'password'])->assertUnauthorized();
    $this->postJson('/api/account/login', ['login' => $user->mobile, 'password' => 'NewSecurePassword123!'])->assertOk();
});

test('invalid expired deleted unknown and already used tokens cannot reset a farmer password', function (string $kind) {
    $this->freezeTime();
    $user = User::factory()->create();
    $payload = farmerPasswordResetPayload($user);
    if ($kind === 'invalid') {
        $payload['token'] = 'invalid-token';
    } elseif ($kind === 'expired') {
        $this->travel(61)->minutes();
    } elseif ($kind === 'deleted') {
        $user->delete();
    } elseif ($kind === 'unknown') {
        $payload['email'] = 'unknown@example.com';
    } elseif ($kind === 'used') {
        $this->postJson('/api/account/reset-password', $payload)->assertOk();
        $payload['password'] = $payload['password_confirmation'] = 'DifferentPassword123!';
    }
    Event::fake([PasswordReset::class]);

    $this->postJson('/api/account/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
    $expected = $kind === 'used' ? 'NewSecurePassword123!' : 'password';
    expect(Hash::check($expected, User::withTrashed()->findOrFail($user->id)->password))->toBeTrue();
    Event::assertNotDispatched(PasswordReset::class);
})->with(['invalid', 'expired', 'deleted', 'unknown', 'used']);

test('password reset validates required fields email token and matching strong passwords', function (array $override, string $field) {
    $user = User::factory()->create();
    $payload = farmerPasswordResetPayload($user, overrides: $override);
    $original = $user->password;
    $this->postJson('/api/account/reset-password', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($user->fresh()->password)->toBe($original);
})->with([
    'missing token' => [['token' => null], 'token'], 'token type' => [['token' => []], 'token'],
    'token length' => [['token' => str_repeat('a', 256)], 'token'], 'email format' => [['email' => 'bad-email'], 'email'],
    'missing email' => [['email' => null], 'email'], 'missing confirmation' => [['password_confirmation' => null], 'password_confirmation'],
    'mismatched confirmation' => [['password_confirmation' => 'DifferentPassword123!'], 'password'],
    'weak matching password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'missing password' => [['password' => null], 'password'], 'password type' => [['password' => []], 'password'],
]);

test('farmer and admin password reset tokens remain isolated for the same email and numeric id', function () {
    $user = User::factory()->create();
    $admin = AdminUser::factory()->create(['id' => $user->id, 'email' => $user->email]);
    $adminPassword = $admin->password;
    $adminToken = Password::broker('admin_users')->createToken($admin);
    $userToken = Password::broker('users')->createToken($user);

    $this->postJson('/api/account/reset-password', farmerPasswordResetPayload($user, $adminToken))->assertUnprocessable()->assertJsonValidationErrors('token');
    expect(Password::broker('admin_users')->tokenExists($admin, $adminToken))->toBeTrue();
    $this->postJson('/api/account/reset-password', farmerPasswordResetPayload($user, $userToken))->assertOk();
    expect($admin->fresh()->password)->toBe($adminPassword);
    expect(Password::broker('admin_users')->tokenExists($admin, $adminToken))->toBeTrue();
    expect(Password::broker('users')->tokenExists($user, $userToken))->toBeFalse();
});

test('password and remember token changes roll back when authentication or reset token deletion fails', function (string $table) {
    $user = User::factory()->create(['remember_token' => 'previous-remember-token']);
    $authToken = $user->createToken('test', ['frontend']);
    $payload = farmerPasswordResetPayload($user);
    $originalPassword = $user->password;
    Exceptions::fake();
    Event::fake([PasswordReset::class]);
    DB::unprepared('CREATE TRIGGER fail_farmer_reset_delete BEFORE DELETE ON '.$table." BEGIN SELECT RAISE(ABORT, 'Simulated reset deletion failure'); END");
    try {
        $this->postJson('/api/account/reset-password', $payload)->assertInternalServerError()->assertJsonPath('data', null);
        expect($user->fresh()->password)->toBe($originalPassword);
        expect($user->fresh()->remember_token)->toBe('previous-remember-token');
        $this->assertModelExists($authToken->accessToken);
        expect(Password::broker('users')->tokenExists($user, $payload['token']))->toBeTrue();
        Event::assertNotDispatched(PasswordReset::class);
        Exceptions::assertReported(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_farmer_reset_delete');
    }
    $this->postJson('/api/account/reset-password', $payload)->assertOk();
})->with(['personal_access_tokens', 'password_reset_tokens']);

test('reset requests cannot reactivate suspended farmers or change their profile', function () {
    $user = User::factory()->inactive()->create(['first_name' => 'Original']);
    $this->postJson('/api/account/reset-password', farmerPasswordResetPayload($user, overrides: ['status' => true, 'first_name' => 'Untrusted']))
        ->assertOk();
    expect($user->fresh()->status)->toBeFalse();
    expect($user->fresh()->first_name)->toBe('Original');
    $this->postJson('/api/account/login', ['login' => $user->mobile, 'password' => 'NewSecurePassword123!'])->assertUnauthorized();
});

test('password reset API attempts are rate limited even for unknown accounts', function () {
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/account/reset-password', [
            'email' => 'unknown@example.com', 'token' => 'bad-token', 'password' => 'NewSecurePassword123!', 'password_confirmation' => 'NewSecurePassword123!',
        ])->assertUnprocessable();
    }
    $this->postJson('/api/account/reset-password', [
        'email' => 'unknown@example.com', 'token' => 'bad-token', 'password' => 'NewSecurePassword123!', 'password_confirmation' => 'NewSecurePassword123!',
    ])->assertTooManyRequests()->assertHeader('Retry-After');
});

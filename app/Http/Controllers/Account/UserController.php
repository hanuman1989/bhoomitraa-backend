<?php

namespace App\Http\Controllers\Account;

use App\Helpers\AreaHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ForgotPasswordRequest;
use App\Http\Requests\Account\RegisterRequest;
use App\Http\Requests\Account\ResetPasswordRequest;
use App\Http\Requests\Account\UpdateLocationDetailsRequest;
use App\Http\Requests\Account\UpdatePersonalDetailsRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\FarmerResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

class UserController extends Controller
{
    public function areaUnits(): JsonResponse
    {
        $units = [];
        foreach (AreaHelper::units() as $code => $definition) {
            $units[] = ['value' => $code, ...Arr::only($definition, ['label', 'label_hi', 'square_meter_factor', 'definition'])];
        }

        return response()->json([
            'status' => true,
            'data' => ['default_unit' => config('area_units.default_unit'), 'units' => $units],
            'pagination' => null, 'message' => 'Area units retrieved successfully.',
        ]);
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $attributes = Arr::only($request->validated(), ['first_name', 'last_name', 'mobile', 'email', 'password']);
        $attributes['email'] ??= null;
        try {
            $user = DB::transaction(function () use ($attributes): User {
                $user = User::query()->create($attributes);
                $user->farmerProfile()->create([]);

                return $user;
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to register the farmer. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => new UserResource($user), 'pagination' => null,
            'message' => 'Farmer registered successfully.',
        ], 201);
    }

    public function updatePersonalDetails(UpdatePersonalDetailsRequest $request): JsonResponse
    {
        $attributes = $request->safe()->only(['first_name', 'last_name', 'preferred_language', 'preferred_units']);
        try {
            $user = DB::transaction(function () use ($request, $attributes): User {
                /** Serialize both profile steps on the user, including when no profile exists yet. */
                $user = User::query()->lockForUpdate()->findOrFail($request->user('user')->getKey());
                abort_unless($user->status, 403, 'User account is inactive.');
                $user->update(Arr::only($attributes, ['first_name', 'last_name']));
                $user->farmerProfile()->updateOrCreate([], Arr::only($attributes, ['preferred_language', 'preferred_units']));

                return $user->load(['farmerProfile' => fn (HasOne $profile): HasOne => $profile->withLocationNames()]);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update personal details. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => new UserResource($user), 'pagination' => null,
            'message' => 'Personal details updated successfully.',
        ]);
    }

    public function updateLocationDetails(UpdateLocationDetailsRequest $request): JsonResponse
    {
        $attributes = $request->safe()->only(['state_id', 'district_id', 'village', 'address', 'pincode']);
        try {
            $user = DB::transaction(function () use ($request, $attributes): User {
                $user = User::query()->lockForUpdate()->findOrFail($request->user('user')->getKey());
                abort_unless($user->status, 403, 'User account is inactive.');
                $profile = $user->farmerProfile()->firstOrNew();
                $profile->fill($attributes);
                $errors = $profile->locationErrors(lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $profile->save();

                return $user->load(['farmerProfile' => fn (HasOne $profile): HasOne => $profile->withLocationNames()]);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update location details. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => new UserResource($user), 'pagination' => null,
            'message' => 'Farmer profile updated successfully.',
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        if (FarmerResetPassword::resetBaseUrl() === null) {
            return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                'message' => 'Password recovery is temporarily unavailable. Please try again later.'], 503);
        }

        $email = $request->validated('email');
        try {
            DB::transaction(function () use ($email): void {
                User::query()->where('email', $email)->lockForUpdate()->first();
                Password::broker('users')->sendResetLink(['email' => $email]);
            }, attempts: 3);
        } catch (Throwable $exception) {
            /** Keep delivery failures indistinguishable from unknown accounts and omit transport secrets. */
            Log::error('Unable to send the farmer password reset email.', ['exception_type' => $exception::class]);
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null,
            'message' => 'If an account exists with this email, a password reset link has been sent.']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        try {
            [$status, $resetUser] = DB::transaction(function () use ($credentials): array {
                /** Serialize token validation and consumption with other resets, reset emails and logins. */
                $user = User::query()->where('email', $credentials['email'])->lockForUpdate()->first();
                if ($user === null) {
                    return [Password::INVALID_USER, null];
                }
                $resetUser = null;
                $status = Password::broker('users')->reset($credentials, function (User $user, string $password) use (&$resetUser): void {
                    $user->password = $password;
                    $user->setRememberToken(Str::random(60));
                    $user->save();
                    $user->tokens()->delete();
                    $resetUser = $user;
                });

                return [$status, $resetUser];
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to reset the password. Please try again later.');
        }

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['The password reset token is invalid or has expired.']]);
        }
        event(new PasswordReset($resetUser));

        return response()->json(['status' => true, 'data' => null, 'pagination' => null,
            'message' => 'Password reset successfully. Please login with your new password.']);
    }
}

<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        try {
            $result = DB::transaction(function () use ($credentials): ?array {
                $user = User::query()->where('mobile', $credentials['mobile'])->lockForUpdate()->first();
                if (! $user || ! $user->status || ! $this->passwordMatches($credentials['password'], $user->password)) {
                    return null;
                }

                $expiresAt = now()->addMinutes(config('sanctum.expiration'));
                $token = $user->createToken($credentials['device_name'] ?? 'frontend-api', ['frontend'], $expiresAt);
                $user->last_login_at = now()->startOfSecond();
                $user->save();

                return ['user' => $user, 'token' => $token, 'expires_at' => $expiresAt];
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log in. Please try again later.');
        }

        if ($result === null) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'Invalid credentials or inactive account.',
            ], 401);
        }

        return response()->json([
            'status' => true,
            'data' => [
                'user' => new UserResource($result['user']),
                'access_token' => $result['token']->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at']->toISOString(),
            ],
            'pagination' => null,
            'message' => 'User logged in successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new UserResource($request->user('user')),
            'pagination' => null,
            'message' => 'User retrieved successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user('user')->currentAccessToken()->delete();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log out. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'User logged out successfully.',
        ]);
    }
}

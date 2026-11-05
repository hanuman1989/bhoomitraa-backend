<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();
        try {
            $user = User::where('email', $credentials['email'])->first();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log in. Please try again later.');
        }

        if (! $user || ! $this->passwordMatches($credentials['password'], $user->password)) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'Invalid credentials.',
            ], 401);
        }

        $expiresAt = now()->addMinutes(config('sanctum.expiration'));
        try {
            $token = $user->createToken($credentials['device_name'] ?? 'frontend-api', ['frontend'], $expiresAt);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log in. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => [
                'user' => new UserResource($user),
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
            ],
            'pagination' => null,
            'message' => 'User logged in successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new UserResource($request->user()),
            'pagination' => null,
            'message' => 'User retrieved successfully.',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $request->user()->currentAccessToken()->delete();
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

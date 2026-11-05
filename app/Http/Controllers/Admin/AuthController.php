<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUser;
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
            $adminUser = AdminUser::where('email', $credentials['email'])->first();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log in. Please try again later.');
        }

        if (! $adminUser || ! $adminUser->status || ! $this->passwordMatches($credentials['password'], $adminUser->password)) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'Invalid credentials or inactive account.',
            ], 401);
        }

        $expiresAt = now()->addMinutes(config('sanctum.expiration'));
        try {
            $token = $adminUser->createToken($credentials['device_name'] ?? 'admin-api', ['admin'], $expiresAt);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to log in. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => [
                'admin_user' => new AdminUserResource($adminUser),
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toISOString(),
            ],
            'pagination' => null,
            'message' => 'Admin logged in successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new AdminUserResource($request->user()),
            'pagination' => null,
            'message' => 'Admin retrieved successfully.',
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
            'message' => 'Admin logged out successfully.',
        ]);
    }
}

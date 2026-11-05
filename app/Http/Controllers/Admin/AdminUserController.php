<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateAdminUserPasswordRequest;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:511'],
            'status' => ['sometimes', 'nullable', 'boolean'],
        ]);

        try {
            $query = AdminUser::query();
            $keyword = trim($filters['keyword'] ?? '');

            if ($keyword !== '') {
                $searchPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $fullNameExpression = $query->getConnection()->getDriverName() === 'sqlite'
                    ? "TRIM(first_name || ' ' || last_name)"
                    : "TRIM(CONCAT(first_name, ' ', last_name))";

                $query->where(function (Builder $query) use ($searchPattern, $fullNameExpression): void {
                    $query->whereRaw("first_name LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("last_name LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("{$fullNameExpression} LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("email LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("mobile LIKE ? ESCAPE '!'", [$searchPattern]);
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', (bool) $filters['status']);
            }

            $adminUsers = $query->orderByDesc('id')->paginate($request->integer('per_page', 15))->withQueryString();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve admin users. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => AdminUserResource::collection($adminUsers),
            'pagination' => [
                'current_page' => $adminUsers->currentPage(),
                'last_page' => $adminUsers->lastPage(),
                'per_page' => $adminUsers->perPage(),
                'total' => $adminUsers->total(),
                'from' => $adminUsers->firstItem(),
                'to' => $adminUsers->lastItem(),
                'next_page_url' => $adminUsers->nextPageUrl(),
                'prev_page_url' => $adminUsers->previousPageUrl(),
            ],
            'message' => 'Admin users retrieved successfully.',
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        try {
            $adminUser = DB::transaction(fn (): AdminUser => AdminUser::create(
                $request->safe()->only(['first_name', 'last_name', 'mobile', 'email', 'password', 'status', 'is_super_user']),
            )->refresh());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the admin user. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new AdminUserResource($adminUser),
            'pagination' => null,
            'message' => 'Admin user created successfully.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, AdminUser $adminUser): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new AdminUserResource($adminUser),
            'pagination' => null,
            'message' => 'Admin user retrieved successfully.',
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdminUserRequest $request, AdminUser $adminUser): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $adminUser): void {
                $adminUser->fill($request->safe()->only(['first_name', 'last_name', 'mobile', 'email', 'password', 'status', 'is_super_user']));

                if ($adminUser->isDirty('email')) {
                    $adminUser->email_verified_at = null;
                }

                $revokeTokens = $adminUser->isDirty(['password', 'status', 'is_super_user', 'email']);
                $adminUser->save();

                if ($revokeTokens) {
                    $adminUser->tokens()->delete();
                }
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the admin user. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new AdminUserResource($adminUser),
            'pagination' => null,
            'message' => 'Admin user updated successfully.',
        ]);
    }

    public function updatePassword(UpdateAdminUserPasswordRequest $request, AdminUser $adminUser): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $adminUser): void {
                $adminUser->fill($request->safe()->only('password'));
                $adminUser->save();
                $adminUser->tokens()->delete();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the admin user password. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new AdminUserResource($adminUser),
            'pagination' => null,
            'message' => 'Admin user password updated successfully.',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AdminUser $adminUser): JsonResponse
    {
        try {
            DB::transaction(function () use ($adminUser): void {
                $adminUser->tokens()->delete();
                $adminUser->delete();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the admin user. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Admin user deleted successfully.',
        ]);
    }
}

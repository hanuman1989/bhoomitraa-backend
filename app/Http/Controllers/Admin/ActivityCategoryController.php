<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreActivityCategoryRequest;
use App\Http\Requests\Admin\UpdateActivityCategoryRequest;
use App\Http\Requests\Admin\UpdateActivityCategoryStatusRequest;
use App\Http\Resources\Admin\ActivityCategoryResource;
use App\Models\ActivityCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PDOException;

class ActivityCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'boolean'],
            'is_system' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = ActivityCategory::query()->withLocalizedName();
            if (array_key_exists('status', $filters)) {
                $query->where('activity_categories.is_active', $filters['status']);
            }
            if (array_key_exists('is_system', $filters)) {
                $query->where('activity_categories.is_system', $filters['is_system']);
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("activity_categories.slug LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('translations', fn (Builder $translations): Builder => $translations
                            ->whereIn('locale', array_keys(config('languages.supported')))
                            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                });
            }
            $categories = $query->orderBy('localized_name')->orderBy('activity_categories.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only(['keyword', 'status', 'is_system', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve activity categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => ActivityCategoryResource::collection($categories),
            'pagination' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
                'from' => $categories->firstItem(),
                'to' => $categories->lastItem(),
                'next_page_url' => $categories->nextPageUrl(),
                'prev_page_url' => $categories->previousPageUrl(),
            ],
            'message' => 'Activity categories retrieved successfully.',
        ]);
    }

    public function store(StoreActivityCategoryRequest $request): JsonResponse
    {
        try {
            $category = (new ActivityCategory)->saveWithTranslations(
                $request->safe()->only(['slug', 'icon', 'is_system']), $request->validated('translations'),
            );
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the activity category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new ActivityCategoryResource($category),
            'pagination' => null,
            'message' => 'Activity category created successfully.',
        ], 201);
    }

    public function show(ActivityCategory $activityCategory): JsonResponse
    {
        $activityCategory->load('translations');

        return response()->json([
            'status' => true,
            'data' => new ActivityCategoryResource($activityCategory),
            'pagination' => null,
            'message' => 'Activity category retrieved successfully.',
        ]);
    }

    public function update(UpdateActivityCategoryRequest $request, ActivityCategory $activityCategory): JsonResponse
    {
        try {
            $category = $activityCategory->saveWithTranslations(
                $request->safe()->only(['slug', 'icon']), $request->validated('translations', []),
            );
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the activity category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new ActivityCategoryResource($category),
            'pagination' => null,
            'message' => 'Activity category updated successfully.',
        ]);
    }

    public function updateStatus(UpdateActivityCategoryStatusRequest $request, ActivityCategory $activityCategory): JsonResponse
    {
        try {
            $category = DB::transaction(function () use ($request, $activityCategory): ActivityCategory {
                $category = ActivityCategory::query()->lockForUpdate()->findOrFail($activityCategory->id);
                if ($category->is_system) {
                    Gate::authorize('manage-system-activity-categories');
                }
                $category->load('translations');
                if ($request->boolean('is_active')) {
                    $errors = $category->activationErrors();
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $category->is_active = $request->boolean('is_active');
                $category->save();

                return $category;
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the activity category status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new ActivityCategoryResource($category),
            'pagination' => null,
            'message' => 'Activity category status updated successfully.',
        ]);
    }

    public function destroy(ActivityCategory $activityCategory): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($activityCategory): bool {
                $category = ActivityCategory::query()->lockForUpdate()->findOrFail($activityCategory->id);
                if ($category->is_system || $category->is_active || $category->hasExternalReferences()) {
                    return false;
                }
                $category->delete();

                return true;
            }, attempts: 3);
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
                || (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the activity category. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the activity category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Activity category deleted successfully.',
        ]);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The activity category cannot be deleted while system-protected, active, or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}

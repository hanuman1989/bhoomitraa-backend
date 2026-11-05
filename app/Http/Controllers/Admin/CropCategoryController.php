<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropCategoryRequest;
use App\Http\Requests\Admin\UpdateCropCategoryRequest;
use App\Http\Requests\Admin\UpdateCropCategoryStatusRequest;
use App\Http\Resources\Admin\CropCategoryResource;
use App\Models\CropCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class CropCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = CropCategory::query()->withLocalizedName();
            if (array_key_exists('is_active', $filters)) {
                $query->where('crop_categories.is_active', $filters['is_active']);
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("crop_categories.slug LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
            $categories = $query->orderBy('localized_name')->orderBy('crop_categories.id')
                ->paginate($request->integer('per_page', 15))->appends($request->only([
                    'keyword', 'is_active', 'per_page',
                ]));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropCategoryResource::collection($categories),
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
            'message' => 'Crop categories retrieved successfully.',
        ]);
    }

    public function store(StoreCropCategoryRequest $request): JsonResponse
    {
        try {
            $category = (new CropCategory)->saveWithTranslations($request->safe()->only('slug'), $request->validated('translations'));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropCategoryResource($category),
            'pagination' => null,
            'message' => 'Crop category created successfully.',
        ], 201);
    }

    public function show(CropCategory $cropCategory): JsonResponse
    {
        $cropCategory->load('translations');

        return response()->json([
            'status' => true,
            'data' => new CropCategoryResource($cropCategory),
            'pagination' => null,
            'message' => 'Crop category retrieved successfully.',
        ]);
    }

    public function update(UpdateCropCategoryRequest $request, CropCategory $cropCategory): JsonResponse
    {
        try {
            $category = $cropCategory->saveWithTranslations($request->safe()->only('slug'), $request->validated('translations', []));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropCategoryResource($category),
            'pagination' => null,
            'message' => 'Crop category updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropCategoryStatusRequest $request, CropCategory $cropCategory): JsonResponse
    {
        $category = DB::transaction(function () use ($request, $cropCategory): ?CropCategory {
            $category = CropCategory::query()->lockForUpdate()->findOrFail($cropCategory->id);
            $english = $category->translations()->where('locale', 'en')->first();
            if ($request->boolean('is_active') && ($english === null || blank($english->name))) {
                return null;
            }
            $category->is_active = $request->boolean('is_active');
            $category->save();

            return $category->load('translations');
        });
        if ($category === null) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'An English translation is required before activating the crop category.',
            ], 409);
        }

        return response()->json([
            'status' => true,
            'data' => new CropCategoryResource($category),
            'pagination' => null,
            'message' => 'Crop category status updated successfully.',
        ]);
    }

    public function destroy(CropCategory $cropCategory): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($cropCategory): bool {
                $category = CropCategory::query()->lockForUpdate()->findOrFail($cropCategory->id);
                if ($category->is_active || $category->crops()->exists()) {
                    return false;
                }
                $category->delete();

                return true;
            });
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1451
                || (($exception->errorInfo[0] ?? null) === '23000' && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the crop category. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Crop category deleted successfully.',
        ]);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The crop category cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}

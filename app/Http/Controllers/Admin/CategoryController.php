<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Category::query();
            $keyword = trim($filters['keyword'] ?? '');

            if ($keyword !== '') {
                $searchPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';

                $query->where(function (Builder $query) use ($searchPattern): void {
                    $query->whereRaw("title LIKE ? ESCAPE '!'", [$searchPattern])
                        ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$searchPattern]);
                });
            }

            if (isset($filters['status'])) {
                $query->where('status', (bool) $filters['status']);
            }

            $categories = $query->orderByDesc('id')
                ->paginate($request->integer('per_page', 15))
                ->withQueryString();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve categories. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CategoryResource::collection($categories),
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
            'message' => 'Categories retrieved successfully.',
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        try {
            $category = DB::transaction(function () use ($request): Category {
                $category = new Category($request->safe()->except('image'));
                $category->uploadedImage = $request->file('image');
                $category->save();

                return $category->refresh();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CategoryResource($category),
            'pagination' => null,
            'message' => 'Category created successfully.',
        ], 201);
    }

    public function show(Category $category): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => new CategoryResource($category),
            'pagination' => null,
            'message' => 'Category retrieved successfully.',
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $category): void {
                $category->fill($request->safe()->except('image'));
                $category->uploadedImage = $request->file('image');
                $category->save();
            });
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CategoryResource($category),
            'pagination' => null,
            'message' => 'Category updated successfully.',
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        try {
            DB::transaction(fn (): ?bool => $category->delete());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the category. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Category deleted successfully.',
        ]);
    }
}

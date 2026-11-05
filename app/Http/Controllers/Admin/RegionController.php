<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRegionRequest;
use App\Http\Requests\Admin\UpdateRegionRequest;
use App\Http\Resources\Admin\RegionResource;
use App\Models\Region;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use PDOException;

class RegionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'region_type' => ['sometimes', Rule::in(array_keys(Region::PARENT_TYPES))],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = Region::query()->withLocalizedName()->with([
                'translations' => function (HasMany $translations) use ($request): void {
                    if (! $request->routeIs('admin.*')) {
                        $translations->whereIn('locale', [app()->getLocale(), config('languages.default')]);
                    }
                },
            ]);
            if (isset($filters['parent_id'])) {
                $query->where('regions.parent_id', $filters['parent_id']);
            } elseif (isset($filters['region_type'])) {
                $query->where('regions.region_type', $filters['region_type']);
            } elseif (array_key_exists('parent_id', $filters)) {
                $query->whereNull('regions.parent_id');
            }
            if (array_key_exists('is_active', $filters)) {
                $query->where('regions.is_active', $filters['is_active']);
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $searchPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$searchPattern]);
            }
            $regions = $query->orderBy('localized_name')->orderBy('regions.id')
                ->paginate($request->integer('per_page', 15))->appends($request->only([
                    'keyword', 'region_type', 'parent_id', 'is_active', 'per_page',
                ]));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve regions. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => RegionResource::collection($regions),
            'pagination' => [
                'current_page' => $regions->currentPage(),
                'last_page' => $regions->lastPage(),
                'per_page' => $regions->perPage(),
                'total' => $regions->total(),
                'from' => $regions->firstItem(),
                'to' => $regions->lastItem(),
                'next_page_url' => $regions->nextPageUrl(),
                'prev_page_url' => $regions->previousPageUrl(),
            ],
            'message' => 'Regions retrieved successfully.',
        ]);
    }

    public function store(StoreRegionRequest $request): JsonResponse
    {
        try {
            $region = (new Region)->saveWithTranslations($request->safe()->except('translations'), $request->validated('translations'));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the region. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new RegionResource($region),
            'pagination' => null,
            'message' => 'Region created successfully.',
        ], 201);
    }

    public function show(Region $region): JsonResponse
    {
        $region->load(['translations', 'parent.translations']);

        return response()->json([
            'status' => true,
            'data' => new RegionResource($region),
            'pagination' => null,
            'message' => 'Region retrieved successfully.',
        ]);
    }

    public function update(UpdateRegionRequest $request, Region $region): JsonResponse
    {
        try {
            $region = $region->saveWithTranslations($request->safe()->except('translations'), $request->validated('translations', []));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the region. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new RegionResource($region),
            'pagination' => null,
            'message' => 'Region updated successfully.',
        ]);
    }

    public function destroy(Region $region): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($region): bool {
                $region = Region::query()->lockForUpdate()->findOrFail($region->id);
                if ($region->children()->exists()) {
                    return false;
                }
                $region->delete();

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

            return $this->databaseFailure($exception, 'Unable to delete the region. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the region. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Region deleted successfully.',
        ]);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The region cannot be deleted because it has child regions or is referenced by other records.',
        ], 409);
    }
}

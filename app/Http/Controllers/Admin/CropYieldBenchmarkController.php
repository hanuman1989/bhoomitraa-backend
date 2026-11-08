<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropYieldBenchmarkRequest;
use App\Http\Requests\Admin\UpdateCropYieldBenchmarkRequest;
use App\Http\Requests\Admin\UpdateCropYieldBenchmarkStatusRequest;
use App\Http\Resources\Admin\CropYieldBenchmarkResource;
use App\Models\Crop;
use App\Models\CropYieldBenchmark;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use PDOException;

class CropYieldBenchmarkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')
                ->when($request->filled('crop_id'), fn (Exists $rule): Exists => $rule->where('crop_id', $request->integer('crop_id')))],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'benchmark_type' => ['sometimes', Rule::in(CropYieldBenchmark::BENCHMARK_TYPES)],
            'region_scope' => ['sometimes', Rule::in(CropYieldBenchmark::REGION_SCOPES)],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = CropYieldBenchmark::query()->with($this->displayRelations());
            foreach (['crop_id', 'crop_variety_id', 'season_id', 'benchmark_type', 'region_scope', 'is_active'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $names = static fn (Builder $related): Builder => $related->withLocalizedName()
                        ->where(fn (Builder $related): Builder => $related->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern]));
                    $query->whereHas('crop', $names)->orWhereHas('cropVariety', $names)
                        ->orWhereHas('knowledgeSource', fn (Builder $source): Builder => $source->withLocalizedTitle()
                            ->whereRaw("COALESCE(requested_translation.title, default_translation.title, knowledge_sources.title) LIKE ? ESCAPE '!'", [$pattern]));
                });
            }
            $benchmarks = $query->orderByDesc('created_at')->orderBy('id')->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop yield benchmarks. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => CropYieldBenchmarkResource::collection($benchmarks),
            'pagination' => ['current_page' => $benchmarks->currentPage(), 'last_page' => $benchmarks->lastPage(), 'per_page' => $benchmarks->perPage(),
                'total' => $benchmarks->total(), 'from' => $benchmarks->firstItem(), 'to' => $benchmarks->lastItem(),
                'next_page_url' => $benchmarks->nextPageUrl(), 'prev_page_url' => $benchmarks->previousPageUrl()],
            'message' => 'Crop yield benchmarks retrieved successfully.',
        ]);
    }

    public function store(StoreCropYieldBenchmarkRequest $request): JsonResponse
    {
        try {
            $result = $this->persistBenchmark(new CropYieldBenchmark, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop yield benchmark. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropYieldBenchmarkResource($result), 'pagination' => null, 'message' => 'Crop yield benchmark created successfully.'], 201);
    }

    public function show(CropYieldBenchmark $cropYieldBenchmark): JsonResponse
    {
        $cropYieldBenchmark->load($this->displayRelations(true));

        return response()->json(['status' => true, 'data' => new CropYieldBenchmarkResource($cropYieldBenchmark), 'pagination' => null, 'message' => 'Crop yield benchmark retrieved successfully.']);
    }

    public function update(UpdateCropYieldBenchmarkRequest $request, CropYieldBenchmark $cropYieldBenchmark): JsonResponse
    {
        try {
            $result = $this->persistBenchmark($cropYieldBenchmark, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop yield benchmark. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropYieldBenchmarkResource($result), 'pagination' => null, 'message' => 'Crop yield benchmark updated successfully.']);
    }

    public function updateStatus(UpdateCropYieldBenchmarkStatusRequest $request, CropYieldBenchmark $cropYieldBenchmark): JsonResponse
    {
        try {
            $result = $this->persistBenchmark($cropYieldBenchmark, $request->validated(), statusOnly: true);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop yield benchmark status. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropYieldBenchmarkResource($result), 'pagination' => null, 'message' => 'Crop yield benchmark status updated successfully.']);
    }

    /** @param array<string, mixed> $input */
    private function persistBenchmark(CropYieldBenchmark $existing, array $input, bool $statusOnly = false): CropYieldBenchmark|JsonResponse
    {
        $id = $existing->exists ? $existing->id : null;

        return DB::transaction(function () use ($existing, $id, $input, $statusOnly): CropYieldBenchmark|JsonResponse {
            $benchmark = $id === null ? clone $existing : CropYieldBenchmark::query()->lockForUpdate()->findOrFail($id);
            if ($benchmark->exists && $benchmark->is_active && ! $statusOnly) {
                Gate::authorize('review-knowledge-sources');
            }
            /** Serialize first inserts and subsequent writes before any consistent snapshot reads. */
            $cropIds = array_values(array_unique(array_map('intval', array_filter([$benchmark->crop_id, $input['crop_id'] ?? null]))));
            Crop::query()->whereIn('id', $cropIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $benchmark->loadMissing('regions');
            $regionIds = $benchmark->resolveRegionIds($input);
            $benchmark->fill(Arr::only($input, $benchmark->getFillable()));
            if (! $statusOnly || $benchmark->is_active) {
                $errors = $benchmark->validationErrors($regionIds, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($benchmark->is_active) {
                Gate::authorize('review-knowledge-sources');
                $conflict = $benchmark->overlappingActiveBenchmark($regionIds);
                if ($conflict !== null) {
                    return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                        'message' => 'Active crop yield benchmark '.$conflict->id.' already covers the same scientific identity and district coverage. Revise or deactivate the conflicting benchmark.'], 409);
                }
            }
            $syncRegions = ! $benchmark->exists || array_key_exists('region_ids', $input) || array_key_exists('region_scope', $input);
            $benchmark->save();
            if ($syncRegions) {
                $benchmark->regions()->sync($regionIds);
            }

            return $benchmark->load($this->displayRelations(true));
        }, attempts: 3);
    }

    public function destroy(CropYieldBenchmark $cropYieldBenchmark): JsonResponse
    {
        try {
            DB::transaction(function () use ($cropYieldBenchmark): void {
                $benchmark = CropYieldBenchmark::query()->lockForUpdate()->findOrFail($cropYieldBenchmark->id);
                abort_if($benchmark->is_active || $benchmark->hasExternalReferences(), 409, 'Active or referenced crop yield benchmarks cannot be deleted. Deactivate the benchmark instead.');
                $benchmark->delete();
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop yield benchmark. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Crop yield benchmark deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function displayRelations(bool $includeRegions = false): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $names = static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = [
            'crop:id', 'crop.translations' => $names, 'cropVariety:id', 'cropVariety.translations' => $names,
            'season:id', 'season.translations' => $names,
            'knowledgeSource:id,title,publisher,source_type,source_url,reference_identifier',
            'knowledgeSource.translations' => static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)->select(['id', 'knowledge_source_id', 'locale', 'title']),
        ];
        if ($includeRegions) {
            $relations['regions'] = static fn (BelongsToMany $regions): BelongsToMany => $regions->orderBy('regions.id');
            $relations['regions.translations'] = $names;
        }

        return $relations;
    }
}

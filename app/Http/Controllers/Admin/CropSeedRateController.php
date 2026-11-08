<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropSeedRateRequest;
use App\Http\Requests\Admin\UpdateCropSeedRateRequest;
use App\Http\Requests\Admin\UpdateCropSeedRateStatusRequest;
use App\Http\Resources\Admin\CropSeedRateResource;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\Seed;
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

class CropSeedRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')
                ->when($request->filled('crop_id'), fn (Exists $rule): Exists => $rule->where('crop_id', $request->integer('crop_id')))],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'material_type' => ['sometimes', Rule::in(Seed::MATERIAL_TYPES)],
            'region_scope' => ['sometimes', Rule::in(CropSeedRate::REGION_SCOPES)],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = CropSeedRate::query()->with($this->displayRelations());
            foreach (['crop_id', 'crop_variety_id', 'season_id', 'material_type', 'region_scope', 'is_active'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("sowing_method LIKE ? ESCAPE '!'", [$pattern]);
                    foreach (['crop', 'cropVariety', 'seed'] as $relation) {
                        $query->orWhereHas($relation, fn (Builder $related): Builder => $related->withLocalizedName()
                            ->where(function (Builder $related) use ($pattern, $relation): void {
                                $related->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                                    ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern]);
                                if ($relation === 'seed') {
                                    $related->orWhereRaw("seeds.sku LIKE ? ESCAPE '!'", [$pattern]);
                                }
                            }));
                    }
                });
            }
            $rates = $query->orderByDesc('created_at')->orderBy('id')->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop seed rates. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => CropSeedRateResource::collection($rates),
            'pagination' => ['current_page' => $rates->currentPage(), 'last_page' => $rates->lastPage(), 'per_page' => $rates->perPage(),
                'total' => $rates->total(), 'from' => $rates->firstItem(), 'to' => $rates->lastItem(),
                'next_page_url' => $rates->nextPageUrl(), 'prev_page_url' => $rates->previousPageUrl()],
            'message' => 'Crop seed rates retrieved successfully.',
        ]);
    }

    public function store(StoreCropSeedRateRequest $request): JsonResponse
    {
        try {
            $result = $this->persistRate(new CropSeedRate, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop seed rate. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropSeedRateResource($result), 'pagination' => null, 'message' => 'Crop seed rate created successfully.'], 201);
    }

    public function show(CropSeedRate $cropSeedRate): JsonResponse
    {
        $cropSeedRate->load($this->displayRelations(true));

        return response()->json(['status' => true, 'data' => new CropSeedRateResource($cropSeedRate), 'pagination' => null, 'message' => 'Crop seed rate retrieved successfully.']);
    }

    public function update(UpdateCropSeedRateRequest $request, CropSeedRate $cropSeedRate): JsonResponse
    {
        try {
            $result = $this->persistRate($cropSeedRate, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop seed rate. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropSeedRateResource($result), 'pagination' => null, 'message' => 'Crop seed rate updated successfully.']);
    }

    public function updateStatus(UpdateCropSeedRateStatusRequest $request, CropSeedRate $cropSeedRate): JsonResponse
    {
        try {
            $result = $this->persistRate($cropSeedRate, $request->validated(), statusOnly: true);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop seed rate status. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new CropSeedRateResource($result), 'pagination' => null, 'message' => 'Crop seed rate status updated successfully.']);
    }

    /** @param array<string, mixed> $input */
    private function persistRate(CropSeedRate $existing, array $input, bool $statusOnly = false): CropSeedRate|JsonResponse
    {
        $id = $existing->exists ? $existing->id : null;

        return DB::transaction(function () use ($existing, $id, $input, $statusOnly): CropSeedRate|JsonResponse {
            $rate = $id === null ? clone $existing : CropSeedRate::query()->lockForUpdate()->findOrFail($id);
            if ($rate->is_active && ! $statusOnly) {
                Gate::authorize('review-knowledge-sources');
            }
            /** Serialize first inserts and subsequent writes before any consistent snapshot reads. */
            $cropIds = array_values(array_unique(array_map('intval', array_filter([$rate->crop_id, $input['crop_id'] ?? null]))));
            Crop::query()->whereIn('id', $cropIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $rate->loadMissing('regions');
            $regionIds = $rate->resolveRegionIds($input);
            $rate->fill(Arr::only($input, $rate->getFillable()));
            if (! $statusOnly || $rate->is_active) {
                $errors = $rate->validationErrors($regionIds, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($rate->is_active) {
                Gate::authorize('review-knowledge-sources');
                $conflict = $rate->overlappingActiveRate($regionIds);
                if ($conflict !== null) {
                    return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                        'message' => 'Active crop seed rate '.$conflict->id.' already covers the same recommendation conditions and district coverage. Revise or deactivate the conflicting recommendation.'], 409);
                }
            }
            $syncRegions = ! $rate->exists || array_key_exists('region_ids', $input) || array_key_exists('region_scope', $input);
            $rate->save();
            if ($syncRegions) {
                $rate->regions()->sync($regionIds);
            }

            return $rate->load($this->displayRelations(true));
        }, attempts: 3);
    }

    public function destroy(CropSeedRate $cropSeedRate): JsonResponse
    {
        try {
            DB::transaction(function () use ($cropSeedRate): void {
                $rate = CropSeedRate::query()->lockForUpdate()->findOrFail($cropSeedRate->id);
                abort_if($rate->is_active || $rate->hasExternalReferences(), 409, 'Active or referenced crop seed rates cannot be deleted. Deactivate the recommendation instead.');
                $rate->delete();
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop seed rate. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Crop seed rate deleted successfully.']);
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
            'seed:id,crop_id,crop_variety_id,sku,material_type,default_unit_code,reference_pack_quantity,reference_pack_unit_code',
            'seed.translations' => $names, 'seed.primaryImage',
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

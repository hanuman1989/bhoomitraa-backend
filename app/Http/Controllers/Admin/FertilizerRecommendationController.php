<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFertilizerRecommendationRequest;
use App\Http\Requests\Admin\UpdateFertilizerRecommendationRequest;
use App\Http\Requests\Admin\UpdateFertilizerRecommendationStatusRequest;
use App\Http\Resources\Admin\FertilizerRecommendationResource;
use App\Models\Crop;
use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
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

class FertilizerRecommendationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')
                ->when($request->filled('crop_id'), fn (Exists $rule): Exists => $rule->where('crop_id', $request->integer('crop_id')))],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'recommendation_type' => ['sometimes', Rule::in(FertilizerRecommendation::RECOMMENDATION_TYPES)],
            'region_scope' => ['sometimes', Rule::in(FertilizerRecommendation::REGION_SCOPES)],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = FertilizerRecommendation::query()->with($this->displayRelations());
            foreach (['crop_id', 'crop_variety_id', 'season_id', 'recommendation_type', 'region_scope', 'is_active'] as $field) {
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
                    $query->orWhereRaw("fertilizer_recommendations.reference_code LIKE ? ESCAPE '!'", [$pattern]);
                });
            }
            $recommendations = $query->orderByDesc('created_at')->orderBy('id')->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve fertilizer recommendations. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => FertilizerRecommendationResource::collection($recommendations),
            'pagination' => ['current_page' => $recommendations->currentPage(), 'last_page' => $recommendations->lastPage(), 'per_page' => $recommendations->perPage(),
                'total' => $recommendations->total(), 'from' => $recommendations->firstItem(), 'to' => $recommendations->lastItem(),
                'next_page_url' => $recommendations->nextPageUrl(), 'prev_page_url' => $recommendations->previousPageUrl()],
            'message' => 'Fertilizer recommendations retrieved successfully.',
        ]);
    }

    public function options(): JsonResponse
    {
        $labels = [
            'general' => 'General recommendation', 'irrigated' => 'Irrigated', 'rainfed' => 'Rainfed',
            'sandy' => 'Sandy', 'loamy' => 'Loamy', 'clayey' => 'Clayey',
            'soil_application' => 'Soil application', 'foliar_spray' => 'Foliar spray', 'fertigation' => 'Fertigation',
            'drip_application' => 'Drip application', 'other' => 'Other',
            'kg' => 'Kilogram (kg)', 'ha' => 'Hectare (ha)', 'all' => 'All regions', 'specific' => 'Specific regions',
            'N' => 'Nitrogen (N)', 'P2O5' => 'Phosphorus (P2O5)', 'K2O' => 'Potassium (K2O)', 'S' => 'Sulphur (S)',
            'Zn' => 'Zinc (Zn)', 'Fe' => 'Iron (Fe)', 'Mn' => 'Manganese (Mn)', 'Cu' => 'Copper (Cu)',
            'B' => 'Boron (B)', 'Ca' => 'Calcium (Ca)', 'Mg' => 'Magnesium (Mg)', 'OC' => 'Organic carbon (OC)',
        ];
        $data = [];
        foreach ([
            'recommendation_types' => FertilizerRecommendation::RECOMMENDATION_TYPES,
            'irrigation_conditions' => FertilizerRecommendation::IRRIGATION_CONDITIONS,
            'soil_conditions' => FertilizerRecommendation::SOIL_CONDITIONS,
            'application_methods' => FertilizerRecommendation::APPLICATION_METHODS,
            'nutrient_codes' => FertilizerRecommendationNutrient::CODES,
            'unit_codes' => FertilizerRecommendationNutrient::unitCodes(),
            'area_unit_codes' => FertilizerRecommendationNutrient::areaUnitCodes(),
            'region_scopes' => FertilizerRecommendation::REGION_SCOPES,
        ] as $key => $values) {
            $data[$key] = array_map(static fn (string $value): array => ['value' => $value, 'label' => $labels[$value] ?? $value], $values);
        }
        $data['soil_condition_definition'] = 'Soil texture; does not describe pH, fertility or salinity.';

        return response()->json(['status' => true, 'data' => $data, 'pagination' => null, 'message' => 'Fertilizer recommendation options retrieved successfully.']);
    }

    public function store(StoreFertilizerRecommendationRequest $request): JsonResponse
    {
        try {
            $result = $this->persistRecommendation(new FertilizerRecommendation, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the fertilizer recommendation. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new FertilizerRecommendationResource($result), 'pagination' => null, 'message' => 'Fertilizer recommendation created successfully.'], 201);
    }

    public function show(FertilizerRecommendation $fertilizerRecommendation): JsonResponse
    {
        $fertilizerRecommendation->load($this->displayRelations(true));

        return response()->json(['status' => true, 'data' => new FertilizerRecommendationResource($fertilizerRecommendation), 'pagination' => null, 'message' => 'Fertilizer recommendation retrieved successfully.']);
    }

    public function update(UpdateFertilizerRecommendationRequest $request, FertilizerRecommendation $fertilizerRecommendation): JsonResponse
    {
        try {
            $result = $this->persistRecommendation($fertilizerRecommendation, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the fertilizer recommendation. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new FertilizerRecommendationResource($result), 'pagination' => null, 'message' => 'Fertilizer recommendation updated successfully.']);
    }

    public function updateStatus(UpdateFertilizerRecommendationStatusRequest $request, FertilizerRecommendation $fertilizerRecommendation): JsonResponse
    {
        try {
            $result = $this->persistRecommendation($fertilizerRecommendation, $request->validated(), statusOnly: true);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the fertilizer recommendation status. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new FertilizerRecommendationResource($result), 'pagination' => null, 'message' => 'Fertilizer recommendation status updated successfully.']);
    }

    /** @param array<string, mixed> $input */
    private function persistRecommendation(FertilizerRecommendation $existing, array $input, bool $statusOnly = false): FertilizerRecommendation|JsonResponse
    {
        $id = $existing->exists ? $existing->id : null;

        return DB::transaction(function () use ($existing, $id, $input, $statusOnly): FertilizerRecommendation|JsonResponse {
            $recommendation = $id === null ? clone $existing : FertilizerRecommendation::query()->lockForUpdate()->findOrFail($id);
            if ($recommendation->exists && $recommendation->is_active && ! $statusOnly) {
                Gate::authorize('review-knowledge-sources');
            }
            /** Serialize first inserts and subsequent writes before any consistent snapshot reads. */
            $cropIds = array_values(array_unique(array_map('intval', array_filter([$recommendation->crop_id, $input['crop_id'] ?? null]))));
            Crop::query()->whereIn('id', $cropIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $recommendation->loadMissing('regions');
            $regionIds = $recommendation->resolveRegionIds($input);
            $recommendation->fill(Arr::only($input, $recommendation->getFillable()));
            if (! $statusOnly || $recommendation->is_active) {
                $errors = $recommendation->validationErrors($regionIds, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($recommendation->is_active) {
                Gate::authorize('review-knowledge-sources');
                $conflict = $recommendation->overlappingActiveRecommendation($regionIds);
                if ($conflict !== null) {
                    return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                        'message' => 'Active fertilizer recommendation '.$conflict->id.' already covers the same scientific identity and district coverage. Revise or deactivate the conflicting recommendation.'], 409);
                }
            }
            $syncRegions = ! $recommendation->exists || array_key_exists('region_ids', $input) || array_key_exists('region_scope', $input);
            if (array_key_exists('nutrients', $input)) {
                $codes = array_column($input['nutrients'], 'nutrient_code');
                $savedNutrients = $recommendation->nutrients()->lockForUpdate()->get();
                $changed = $recommendation->exists && ($savedNutrients->count() !== count($input['nutrients']) || $savedNutrients->contains(function (FertilizerRecommendationNutrient $row) use ($input, $codes): bool {
                    $incoming = collect($input['nutrients'])->firstWhere('nutrient_code', $row->nutrient_code);

                    return ! in_array($row->nutrient_code, $codes, true) || $row->fill($incoming ?? [])->isDirty();
                }));
                abort_if($changed && $recommendation->hasExternalReferences(), 409, 'Referenced historical nutrient targets cannot be rewritten. Deactivate this record and create a new recommendation.');
            } elseif (! $statusOnly || $recommendation->is_active) {
                $errors = $recommendation->storedNutrientErrors();
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            $recommendation->save();
            if (array_key_exists('nutrients', $input)) {
                $recommendation->nutrients()->whereNotIn('nutrient_code', $codes)->delete();
                foreach ($input['nutrients'] as $row) {
                    $recommendation->nutrients()->updateOrCreate(['nutrient_code' => $row['nutrient_code']], $row);
                }
            }
            if ($syncRegions) {
                $recommendation->regions()->sync($regionIds);
            }

            return $recommendation->load($this->displayRelations(true));
        }, attempts: 3);
    }

    public function destroy(FertilizerRecommendation $fertilizerRecommendation): JsonResponse
    {
        try {
            DB::transaction(function () use ($fertilizerRecommendation): void {
                $recommendation = FertilizerRecommendation::query()->lockForUpdate()->findOrFail($fertilizerRecommendation->id);
                abort_if($recommendation->is_active || $recommendation->hasExternalReferences(), 409, 'Active or referenced fertilizer recommendations cannot be deleted. Deactivate the recommendation instead.');
                $recommendation->delete();
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the fertilizer recommendation. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Fertilizer recommendation deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function displayRelations(bool $includeRegions = false): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $names = static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = [
            'nutrients',
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

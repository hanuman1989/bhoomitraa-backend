<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropVarietyRequest;
use App\Http\Requests\Admin\UpdateCropVarietyRequest;
use App\Http\Requests\Admin\UpdateCropVarietyStatusRequest;
use App\Http\Resources\Admin\CropVarietyResource;
use App\Models\CropVariety;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;

class CropVarietyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'source_id' => ['sometimes', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'status' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = CropVariety::query()
                ->withLocalizedName()
                ->with([
                    'crop.translations' => fn (HasMany $translations): HasMany => $translations
                        ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')])),
                    'source:id,title',
                ]);

            if (array_key_exists('crop_id', $filters)) {
                $query->where('crop_varieties.crop_id', $filters['crop_id']);
            }
            if (array_key_exists('source_id', $filters)) {
                $query->where('crop_varieties.source_id', $filters['source_id']);
            }
            if (array_key_exists('status', $filters)) {
                $query->where('crop_varieties.is_active', $filters['status']);
            }

            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("crop_varieties.slug LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("crop_varieties.variety_code LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereHas('crop', fn (Builder $crop): Builder => $crop->withLocalizedName()
                            ->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern]));
                });
            }

            $varieties = $query->orderBy('localized_name')->orderBy('crop_varieties.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only(['keyword', 'crop_id', 'source_id', 'status', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop varieties. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropVarietyResource::collection($varieties),
            'pagination' => [
                'current_page' => $varieties->currentPage(),
                'last_page' => $varieties->lastPage(),
                'per_page' => $varieties->perPage(),
                'total' => $varieties->total(),
                'from' => $varieties->firstItem(),
                'to' => $varieties->lastItem(),
                'next_page_url' => $varieties->nextPageUrl(),
                'prev_page_url' => $varieties->previousPageUrl(),
            ],
            'message' => 'Crop varieties retrieved successfully.',
        ]);
    }

    public function store(StoreCropVarietyRequest $request): JsonResponse
    {
        try {
            $variety = $this->saveVariety(
                new CropVariety,
                $request->safe()->only([
                    'crop_id', 'slug', 'variety_code', 'maturity_days_min', 'maturity_days_max', 'source_id', 'is_active',
                ]),
                $request->validated('translations', []),
            );
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop variety. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropVarietyResource($variety),
            'pagination' => null,
            'message' => 'Crop variety created successfully.',
        ], 201);
    }

    public function show(CropVariety $cropVariety): JsonResponse
    {
        $cropVariety->load(['crop.translations', 'translations', 'source:id,title']);

        return response()->json([
            'status' => true,
            'data' => new CropVarietyResource($cropVariety),
            'pagination' => null,
            'message' => 'Crop variety retrieved successfully.',
        ]);
    }

    public function update(UpdateCropVarietyRequest $request, CropVariety $cropVariety): JsonResponse
    {
        try {
            $variety = $this->saveVariety(
                $cropVariety,
                $request->safe()->only([
                    'crop_id', 'slug', 'variety_code', 'maturity_days_min', 'maturity_days_max', 'source_id', 'is_active',
                ]),
                $request->validated('translations', []),
            );
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop variety. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropVarietyResource($variety),
            'pagination' => null,
            'message' => 'Crop variety updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropVarietyStatusRequest $request, CropVariety $cropVariety): JsonResponse
    {
        try {
            $variety = DB::transaction(function () use ($request, $cropVariety): CropVariety {
                $variety = CropVariety::query()->lockForUpdate()->findOrFail($cropVariety->id);
                $variety->is_active = $request->boolean('is_active');
                $errors = $variety->publicationErrors(lockRelationships: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $variety->save();

                return $variety->load(['crop.translations', 'translations', 'source:id,title']);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop variety status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropVarietyResource($variety),
            'pagination' => null,
            'message' => 'Crop variety status updated successfully.',
        ]);
    }

    public function destroy(CropVariety $cropVariety): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($cropVariety): bool {
                $variety = CropVariety::query()->lockForUpdate()->findOrFail($cropVariety->id);
                if ($variety->is_active || $variety->hasExternalReferences()) {
                    return false;
                }
                $variety->delete();

                return true;
            }, attempts: 3);
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if ($this->isForeignKeyViolation($exception)) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the crop variety. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop variety. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Crop variety deleted successfully.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{name?: string, description?: string|null, region_notes?: string|null}>  $translations
     */
    private function saveVariety(CropVariety $variety, array $attributes, array $translations): CropVariety
    {
        $varietyId = $variety->exists ? $variety->id : null;

        return DB::transaction(function () use ($varietyId, $attributes, $translations): CropVariety {
            $variety = $varietyId === null
                ? new CropVariety
                : CropVariety::query()->lockForUpdate()->findOrFail($varietyId);

            if (isset($attributes['crop_id'])
                && (int) $attributes['crop_id'] !== (int) $variety->crop_id
                && $variety->hasExternalReferences()) {
                throw ValidationException::withMessages([
                    'crop_id' => ['The parent crop cannot change while this variety is referenced by other records.'],
                ]);
            }

            $variety->fill($attributes);
            $errors = $variety->publicationErrors($translations, lockRelationships: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $variety->save();

            foreach ($translations as $locale => $values) {
                $variety->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $variety->load(['crop.translations', 'translations', 'source:id,title']);
        }, attempts: 3);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The crop variety cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        return in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
            || (($exception->errorInfo[0] ?? null) === '23000'
                && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'));
    }
}

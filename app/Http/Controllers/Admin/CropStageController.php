<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropStageRequest;
use App\Http\Requests\Admin\UpdateCropStageRequest;
use App\Http\Requests\Admin\UpdateCropStageStatusRequest;
use App\Http\Resources\Admin\CropStageResource;
use App\Models\CropStage;
use App\Models\CropTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;

class CropStageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'status' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = CropStage::query()->withLocalizedName()->with([
                'crop.translations' => fn (HasMany $translations): HasMany => $translations->where('locale', config('languages.default')),
            ]);
            if (array_key_exists('crop_id', $filters)) {
                $query->where('crop_stages.crop_id', $filters['crop_id']);
            }
            if (array_key_exists('status', $filters)) {
                $query->where('crop_stages.is_active', $filters['status']);
            }

            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("crop_stages.code LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern]);
                });
            }

            if (! array_key_exists('crop_id', $filters)) {
                $query->orderBy(CropTranslation::query()->select('name')
                    ->whereColumn('crop_id', 'crop_stages.crop_id')
                    ->where('locale', config('languages.default'))->limit(1));
            }
            $stages = $query->orderBy('crop_stages.sequence')->orderBy('crop_stages.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only(['keyword', 'crop_id', 'status', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve growth stages. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropStageResource::collection($stages),
            'pagination' => [
                'current_page' => $stages->currentPage(),
                'last_page' => $stages->lastPage(),
                'per_page' => $stages->perPage(),
                'total' => $stages->total(),
                'from' => $stages->firstItem(),
                'to' => $stages->lastItem(),
                'next_page_url' => $stages->nextPageUrl(),
                'prev_page_url' => $stages->previousPageUrl(),
            ],
            'message' => 'Growth stages retrieved successfully.',
        ]);
    }

    public function store(StoreCropStageRequest $request): JsonResponse
    {
        try {
            $stage = $this->saveStage(new CropStage, $request->safe()->only(['crop_id', 'code', 'sequence']), $request->validated('translations'));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the growth stage. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageResource($stage),
            'pagination' => null,
            'message' => 'Growth stage created successfully.',
        ], 201);
    }

    public function show(CropStage $cropStage): JsonResponse
    {
        $cropStage->load(['crop.translations', 'translations']);

        return response()->json([
            'status' => true,
            'data' => new CropStageResource($cropStage),
            'pagination' => null,
            'message' => 'Growth stage retrieved successfully.',
        ]);
    }

    public function update(UpdateCropStageRequest $request, CropStage $cropStage): JsonResponse
    {
        try {
            $stage = $this->saveStage($cropStage, $request->safe()->only(['crop_id', 'code', 'sequence']), $request->validated('translations', []));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the growth stage. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageResource($stage),
            'pagination' => null,
            'message' => 'Growth stage updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropStageStatusRequest $request, CropStage $cropStage): JsonResponse
    {
        try {
            $stage = DB::transaction(function () use ($request, $cropStage): CropStage {
                $stage = CropStage::query()->lockForUpdate()->findOrFail($cropStage->id);
                $stage->is_active = $request->boolean('is_active');
                $errors = $stage->activationErrors(lockCrop: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $stage->save();

                return $stage->load(['crop.translations', 'translations']);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the growth stage status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageResource($stage),
            'pagination' => null,
            'message' => 'Growth stage status updated successfully.',
        ]);
    }

    public function destroy(CropStage $cropStage): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($cropStage): bool {
                $stage = CropStage::query()->lockForUpdate()->findOrFail($cropStage->id);
                if ($stage->is_active || $stage->hasExternalReferences()) {
                    return false;
                }
                $stage->delete();

                return true;
            }, attempts: 3);
            if (! $deleted) {
                return $this->deletionConflict();
            }
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
                || (($exception->errorInfo[0] ?? null) === '23000'
                    && str_contains($exception->getMessage(), 'FOREIGN KEY constraint failed'))) {
                return $this->deletionConflict();
            }

            return $this->databaseFailure($exception, 'Unable to delete the growth stage. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the growth stage. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Growth stage deleted successfully.',
        ]);
    }

    /**
     * @param  array{crop_id?: int, code?: string, sequence?: int}  $attributes
     * @param  array<string, array{name?: string, description?: string|null}>  $translations
     */
    private function saveStage(CropStage $stage, array $attributes, array $translations): CropStage
    {
        $stageId = $stage->exists ? $stage->id : null;

        return DB::transaction(function () use ($stageId, $attributes, $translations): CropStage {
            $stage = $stageId === null ? new CropStage : CropStage::query()->lockForUpdate()->findOrFail($stageId);
            if ($stage->exists && isset($attributes['crop_id']) && (int) $attributes['crop_id'] !== $stage->crop_id) {
                throw ValidationException::withMessages([
                    'crop_id' => ['The parent crop cannot be changed after creating a growth stage.'],
                ]);
            }

            $stage->fill($attributes);
            if (! $stage->exists) {
                $stage->is_active = false;
            }
            $errors = $stage->activationErrors($translations, lockCrop: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $stage->save();
            foreach ($translations as $locale => $values) {
                $stage->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $stage->load(['crop.translations', 'translations']);
        }, attempts: 3);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The growth stage cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}

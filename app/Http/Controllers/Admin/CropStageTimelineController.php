<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropStageTimelineRequest;
use App\Http\Requests\Admin\UpdateCropStageTimelineRequest;
use App\Http\Requests\Admin\UpdateCropStageTimelineStatusRequest;
use App\Http\Resources\Admin\CropStageTimelineResource;
use App\Models\CropStageTimeline;
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
use Illuminate\Validation\ValidationException;
use PDOException;

class CropStageTimelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_stage_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_stages', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'status' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $query = CropStageTimeline::query()->with($this->displayRelations());
            foreach (['crop_stage_id', 'crop_variety_id', 'season_id'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            if (array_key_exists('crop_id', $filters)) {
                $query->whereHas('cropStage', fn (Builder $stage): Builder => $stage->where('crop_id', $filters['crop_id']));
            }
            if (array_key_exists('status', $filters)) {
                $query->where('is_active', $filters['status']);
            }

            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $matchingNames = static fn (Builder $related): Builder => $related->withLocalizedName()
                    ->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern]);
                $query->where(function (Builder $query) use ($matchingNames, $pattern): void {
                    $query->whereHas('cropStage', $matchingNames)
                        ->orWhereHas('cropStage.crop', $matchingNames)
                        ->orWhereHas('cropVariety', $matchingNames)
                        ->orWhereHas('season', $matchingNames)
                        ->orWhereHas('source', fn (Builder $source): Builder => $source->withLocalizedTitle()
                            ->whereRaw("COALESCE(requested_translation.title, default_translation.title, knowledge_sources.title) LIKE ? ESCAPE '!'", [$pattern]));
                });
            }
            $timelines = $query->latest()->orderByDesc('id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only(['keyword', 'crop_id', 'crop_stage_id', 'crop_variety_id', 'season_id', 'status', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve stage timelines. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropStageTimelineResource::collection($timelines),
            'pagination' => [
                'current_page' => $timelines->currentPage(),
                'last_page' => $timelines->lastPage(),
                'per_page' => $timelines->perPage(),
                'total' => $timelines->total(),
                'from' => $timelines->firstItem(),
                'to' => $timelines->lastItem(),
                'next_page_url' => $timelines->nextPageUrl(),
                'prev_page_url' => $timelines->previousPageUrl(),
            ],
            'message' => 'Stage timelines retrieved successfully.',
        ]);
    }

    public function store(StoreCropStageTimelineRequest $request): JsonResponse
    {
        try {
            $result = $this->saveTimeline(new CropStageTimeline, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the stage timeline. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageTimelineResource($result),
            'pagination' => null,
            'message' => 'Stage timeline created successfully.',
        ], 201);
    }

    public function show(CropStageTimeline $cropStageTimeline): JsonResponse
    {
        $cropStageTimeline->load($this->displayRelations(includeRegions: true));

        return response()->json([
            'status' => true,
            'data' => new CropStageTimelineResource($cropStageTimeline),
            'pagination' => null,
            'message' => 'Stage timeline retrieved successfully.',
        ]);
    }

    public function update(UpdateCropStageTimelineRequest $request, CropStageTimeline $cropStageTimeline): JsonResponse
    {
        try {
            $result = $this->saveTimeline($cropStageTimeline, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the stage timeline. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageTimelineResource($result),
            'pagination' => null,
            'message' => 'Stage timeline updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropStageTimelineStatusRequest $request, CropStageTimeline $cropStageTimeline): JsonResponse
    {
        try {
            $result = DB::transaction(function () use ($request, $cropStageTimeline): CropStageTimeline|JsonResponse {
                $timeline = CropStageTimeline::query()->lockForUpdate()->findOrFail($cropStageTimeline->id);
                $timeline->is_active = $request->boolean('is_active');
                if ($timeline->is_active) {
                    Gate::authorize('review-knowledge-sources');
                    $regionIds = $timeline->resolveRegionIds([]);
                    $errors = $timeline->validationErrors($regionIds, lockRelationships: true);
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                    $overlap = $timeline->overlappingActiveTimeline($regionIds, lock: true);
                    if ($overlap !== null) {
                        return $this->overlapConflict($overlap->id);
                    }
                }
                $timeline->save();

                return $timeline->load($this->displayRelations(includeRegions: true));
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the stage timeline status. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json([
            'status' => true,
            'data' => new CropStageTimelineResource($result),
            'pagination' => null,
            'message' => 'Stage timeline status updated successfully.',
        ]);
    }

    public function destroy(CropStageTimeline $cropStageTimeline): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($cropStageTimeline): bool {
                $timeline = CropStageTimeline::query()->lockForUpdate()->findOrFail($cropStageTimeline->id);
                if ($timeline->is_active || $timeline->hasExternalReferences()) {
                    return false;
                }
                $timeline->delete();

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

            return $this->databaseFailure($exception, 'Unable to delete the stage timeline. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the stage timeline. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Stage timeline deleted successfully.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function saveTimeline(CropStageTimeline $timeline, array $data): CropStageTimeline|JsonResponse
    {
        $timelineId = $timeline->exists ? $timeline->id : null;

        return DB::transaction(function () use ($timelineId, $data): CropStageTimeline|JsonResponse {
            $timeline = $timelineId === null ? new CropStageTimeline : CropStageTimeline::query()->lockForUpdate()->findOrFail($timelineId);
            if ($timeline->is_active) {
                Gate::authorize('review-knowledge-sources');
            }
            $regionIds = $timeline->resolveRegionIds($data);
            $timeline->fill(Arr::only($data, array_diff($timeline->getFillable(), ['is_active'])));
            if (! $timeline->exists) {
                $timeline->is_active = false;
            }
            $errors = $timeline->validationErrors($regionIds, lockRelationships: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            if ($timeline->is_active) {
                $overlap = $timeline->overlappingActiveTimeline($regionIds, lock: true);
                if ($overlap !== null) {
                    return $this->overlapConflict($overlap->id);
                }
            }
            $syncRegions = ! $timeline->exists || array_key_exists('region_scope', $data) || array_key_exists('region_ids', $data);
            $timeline->save();
            if ($syncRegions) {
                $timeline->regions()->sync($regionIds);
            }

            return $timeline->load($this->displayRelations(includeRegions: true));
        }, attempts: 3);
    }

    /** @return array<int|string, mixed> */
    private function displayRelations(bool $includeRegions = false): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $names = static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales);
        $relations = [
            'cropStage.translations' => $names,
            'cropStage.crop.translations' => $names,
            'cropVariety.translations' => $names,
            'season.translations' => $names,
            'source:id,title',
            'source.translations' => $names,
        ];
        if ($includeRegions) {
            $relations['regions'] = fn (BelongsToMany $regions): BelongsToMany => $regions->orderBy('regions.id');
            $relations['regions.translations'] = $names;
        }

        return $relations;
    }

    private function overlapConflict(int $timelineId): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'Active timeline '.$timelineId.' has overlapping stage, variety, season, and geographic coverage. Deactivate or revise the conflicting rule before publication.',
        ], 409);
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The stage timeline cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}

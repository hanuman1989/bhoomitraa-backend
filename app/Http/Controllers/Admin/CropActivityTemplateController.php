<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCropActivityTemplateRequest;
use App\Http\Requests\Admin\UpdateCropActivityTemplateRequest;
use App\Http\Requests\Admin\UpdateCropActivityTemplateStatusRequest;
use App\Http\Resources\Admin\CropActivityTemplateResource;
use App\Models\CropActivityTemplate;
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

class CropActivityTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'crop_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'category_id' => ['sometimes', 'integer', 'min:1', Rule::exists('activity_categories', 'id')],
            'crop_stage_id' => ['sometimes', 'integer', 'min:1', Rule::exists('crop_stages', 'id')],
            'season_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'source_id' => ['sometimes', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'region_id' => ['sometimes', 'integer', 'min:1', Rule::exists('regions', 'id')],
            'variety_scope' => ['sometimes', Rule::in(CropActivityTemplate::VARIETY_SCOPES)],
            'season_scope' => ['sometimes', Rule::in(CropActivityTemplate::SEASON_SCOPES)],
            'region_scope' => ['sometimes', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'schedule_type' => ['sometimes', Rule::in(CropActivityTemplate::SCHEDULE_TYPES)],
            'schedule_basis' => ['sometimes', Rule::in(CropActivityTemplate::SCHEDULE_BASES)],
            'activity_type' => ['sometimes', Rule::in(CropActivityTemplate::ACTIVITY_TYPES)],
            'priority' => ['sometimes', Rule::in(CropActivityTemplate::PRIORITIES)],
            'status' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = CropActivityTemplate::query()->withLocalizedTitle()->with($this->displayRelations());
            foreach (['crop_id', 'category_id', 'crop_stage_id', 'schedule_type', 'schedule_basis', 'activity_type', 'priority', 'variety_scope', 'season_scope', 'region_scope'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where('crop_activity_templates.'.$field, $filters[$field]);
                }
            }
            if (array_key_exists('crop_variety_id', $filters)) {
                $varietyId = $filters['crop_variety_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('variety_scope', 'all')
                    ->orWhereHas('varieties', fn (Builder $varieties): Builder => $varieties->whereKey($varietyId)));
            }
            if (array_key_exists('season_id', $filters)) {
                $seasonId = $filters['season_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('season_scope', 'all')
                    ->orWhereHas('seasons', fn (Builder $seasons): Builder => $seasons->whereKey($seasonId)));
            }
            if (array_key_exists('source_id', $filters)) {
                $query->whereHas('knowledgeSources', fn (Builder $sources): Builder => $sources->whereKey($filters['source_id']));
            }
            if (array_key_exists('region_id', $filters)) {
                $regionId = $filters['region_id'];
                $query->where(fn (Builder $query): Builder => $query
                    ->where('region_scope', 'all')
                    ->orWhereHas('regions', fn (Builder $regions): Builder => $regions->whereKey($regionId)));
            }
            if (array_key_exists('status', $filters)) {
                $query->where('crop_activity_templates.is_active', $filters['status']);
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $matchingNames = static fn (Builder $translations): Builder => $translations
                    ->whereIn('locale', array_keys(config('languages.supported')))->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
                $query->where(function (Builder $query) use ($matchingNames, $pattern): void {
                    $query->whereHas('translations', fn (Builder $translations): Builder => $translations
                        ->whereIn('locale', array_keys(config('languages.supported')))->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]))
                        ->orWhereHas('crop.translations', $matchingNames)->orWhereHas('category.translations', $matchingNames);
                });
            }
            $templates = $query->orderByDesc('crop_activity_templates.created_at')->orderByDesc('crop_activity_templates.id')
                ->paginate($request->integer('per_page', 15))
                ->appends($request->only([
                    'keyword', 'crop_id', 'crop_variety_id', 'category_id', 'crop_stage_id', 'season_id',
                    'source_id', 'region_id', 'variety_scope', 'season_scope', 'region_scope',
                    'schedule_type', 'schedule_basis', 'activity_type', 'priority', 'status', 'per_page',
                ]));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve crop activity templates. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => CropActivityTemplateResource::collection($templates),
            'pagination' => [
                'current_page' => $templates->currentPage(), 'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(), 'total' => $templates->total(),
                'from' => $templates->firstItem(), 'to' => $templates->lastItem(),
                'next_page_url' => $templates->nextPageUrl(), 'prev_page_url' => $templates->previousPageUrl(),
            ],
            'message' => 'Crop activity templates retrieved successfully.',
        ]);
    }

    public function store(StoreCropActivityTemplateRequest $request): JsonResponse
    {
        try {
            $template = $this->saveTemplate(new CropActivityTemplate, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($template),
            'pagination' => null,
            'message' => 'Crop activity template created successfully.',
        ], 201);
    }

    public function show(CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        $cropActivityTemplate->load($this->displayRelations(includeEditData: true));

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($cropActivityTemplate),
            'pagination' => null,
            'message' => 'Crop activity template retrieved successfully.',
        ]);
    }

    public function update(UpdateCropActivityTemplateRequest $request, CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            $template = $this->saveTemplate($cropActivityTemplate, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($template),
            'pagination' => null,
            'message' => 'Crop activity template updated successfully.',
        ]);
    }

    public function updateStatus(UpdateCropActivityTemplateStatusRequest $request, CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            $template = DB::transaction(function () use ($request, $cropActivityTemplate): CropActivityTemplate {
                $template = CropActivityTemplate::query()->lockForUpdate()->findOrFail($cropActivityTemplate->id);
                $template->is_active = $request->boolean('is_active');
                $regionIds = $template->resolveRegionIds([]);
                if ($template->is_active) {
                    Gate::authorize('review-knowledge-sources');
                    $errors = $template->validationErrors(
                        $regionIds,
                        lockRelationships: true,
                        varietyIds: $template->resolveVarietyIds([]),
                        seasonIds: $template->resolveSeasonIds([]),
                        knowledgeSourceIds: $template->resolveKnowledgeSourceIds([]),
                    );
                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }
                }
                $template->save();
                $template->setAttribute('potential_overlap_ids', $template->is_active ? $template->potentialOverlapIds($regionIds) : []);

                return $template->load($this->displayRelations(includeEditData: true));
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the crop activity template status. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => new CropActivityTemplateResource($template),
            'pagination' => null,
            'message' => 'Crop activity template status updated successfully.',
        ]);
    }

    public function destroy(CropActivityTemplate $cropActivityTemplate): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($cropActivityTemplate): bool {
                $template = CropActivityTemplate::query()->lockForUpdate()->findOrFail($cropActivityTemplate->id);
                if ($template->is_active || $template->hasExternalReferences()) {
                    return false;
                }
                $template->delete();

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

            return $this->databaseFailure($exception, 'Unable to delete the crop activity template. Please try again later.');
        } catch (PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the crop activity template. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Crop activity template deleted successfully.',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function saveTemplate(CropActivityTemplate $template, array $data): CropActivityTemplate
    {
        $templateId = $template->exists ? $template->id : null;

        return DB::transaction(function () use ($templateId, $data): CropActivityTemplate {
            $template = $templateId === null ? new CropActivityTemplate : CropActivityTemplate::query()->lockForUpdate()->findOrFail($templateId);
            if ($template->is_active) {
                Gate::authorize('review-knowledge-sources');
            }
            $regionIds = $template->resolveRegionIds($data);
            $varietyIds = $template->resolveVarietyIds($data);
            $seasonIds = $template->resolveSeasonIds($data);
            $knowledgeSourceIds = $template->resolveKnowledgeSourceIds($data);
            $template->fill(Arr::only($data, array_diff($template->getFillable(), ['is_active', 'trigger_type', 'trigger_config'])));
            $template->trigger_type = 'scheduled';
            $template->trigger_config = null;
            if (! $template->exists) {
                $template->is_active = false;
            }
            if (($data['schedule_basis'] ?? $template->schedule_basis) === 'growth_stage') {
                $template->start_offset_days = null;
                $template->end_offset_days = null;
            }
            $errors = $template->validationErrors(
                $regionIds,
                $data['translations'] ?? [],
                lockRelationships: true,
                varietyIds: $varietyIds,
                seasonIds: $seasonIds,
                knowledgeSourceIds: $knowledgeSourceIds,
            );
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $template->save();
            foreach ($data['translations'] ?? [] as $locale => $translation) {
                $template->translations()->updateOrCreate(['locale' => $locale], $translation);
            }
            if (array_key_exists('region_scope', $data) || array_key_exists('region_ids', $data) || ! $template->wasRecentlyCreated) {
                $template->regions()->sync($template->region_scope === 'all' ? [] : $regionIds);
            }
            $template->varieties()->sync($template->variety_scope === 'all' ? [] : $varietyIds);
            $template->seasons()->sync($template->season_scope === 'all' ? [] : $seasonIds);
            $template->knowledgeSources()->sync($knowledgeSourceIds);
            $template->setAttribute('potential_overlap_ids', $template->is_active ? $template->potentialOverlapIds($regionIds) : []);

            return $template->load($this->displayRelations(includeEditData: true));
        }, attempts: 3);
    }

    /** @return array<int|string, mixed> */
    private function displayRelations(bool $includeEditData = false): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $names = static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = [
            'crop:id,crop_color', 'crop.translations' => $names,
            'category:id', 'category.translations' => $names,
            'cropStage:id', 'cropStage.translations' => $names,
            'varieties:id', 'varieties.translations' => $names,
            'seasons:id,slug,start_month,end_month,icon', 'seasons.translations' => $names,
            'knowledgeSources:id,title,publisher,source_type,source_url,language,reference_identifier,is_active,verification_status',
            'knowledgeSources.translations' => static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)
                ->select(['id', 'knowledge_source_id', 'locale', 'title']),
        ];
        if ($includeEditData) {
            $relations[] = 'translations';
            $relations['regions'] = static fn (BelongsToMany $regions): BelongsToMany => $regions
                ->select(['regions.id', 'regions.parent_id', 'regions.region_type'])->orderBy('regions.id');
            $relations['regions.translations'] = $names;
        }

        return $relations;
    }

    private function deletionConflict(): JsonResponse
    {
        return response()->json([
            'status' => false,
            'data' => null,
            'pagination' => null,
            'message' => 'The crop activity template cannot be deleted while active or referenced by other records. Deactivate it instead.',
        ], 409);
    }
}

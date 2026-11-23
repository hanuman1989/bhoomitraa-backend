<?php

namespace App\Services;

use App\Models\CropActivityTemplate;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared persistence rules for crop activity templates, used by single, bulk, and import writes.
 */
class CropActivityTemplateWriter
{
    /** Fields whose change counts as saving the template's schedule. */
    public const SCHEDULE_FIELDS = ['crop_stage_id', 'schedule_type', 'schedule_basis', 'start_offset_days', 'end_offset_days'];

    public const VERSION_CONFLICT_MESSAGE = 'The crop activity template was changed by another request. Reload it and try again.';

    /**
     * Validate the effective state a create or patch would produce, without persisting it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>
     */
    public function candidateErrors(?CropActivityTemplate $existing, array $data): array
    {
        $metadata = array_diff((new CropActivityTemplate)->getFillable(), ['is_active']);
        $template = $existing instanceof CropActivityTemplate ? clone $existing : new CropActivityTemplate;
        if (! $template->exists) {
            $template->schedule_basis = $data['schedule_basis'] ?? 'fixed_offsets';
            $template->activity_type = $data['activity_type'] ?? 'task';
            $template->allow_status_update = $data['allow_status_update'] ?? true;
            $template->allow_expenses = $data['allow_expenses'] ?? false;
            $template->allow_labour = $data['allow_labour'] ?? false;
            $template->allow_notes = $data['allow_notes'] ?? true;
            $template->allow_attachments = $data['allow_attachments'] ?? false;
            $template->allow_rescheduling = $data['allow_rescheduling'] ?? false;
            $template->smart_actions = $data['smart_actions'] ?? [];
            $template->trigger_type = 'scheduled';
            $template->trigger_config = null;
        }
        $regionIds = $template->resolveRegionIds($data);
        $varietyIds = $template->resolveVarietyIds($data);
        $seasonIds = $template->resolveSeasonIds($data);
        $knowledgeSourceIds = $template->resolveKnowledgeSourceIds($data);
        $template->fill(Arr::only($data, $metadata));
        $this->normalizeSchedule($template, $data);

        return $template->validationErrors(
            $regionIds,
            $data['translations'] ?? [],
            false,
            $varietyIds,
            $seasonIds,
            $knowledgeSourceIds,
        );
    }

    /**
     * Create a template, replaying an earlier creation with the same creation_request_id.
     *
     * @param  array<string, mixed>  $data
     * @return array{template: CropActivityTemplate, created: bool}
     */
    public function create(array $data): array
    {
        $requestId = $data['creation_request_id'] ?? null;
        if ($requestId !== null) {
            $existing = CropActivityTemplate::query()->where('creation_request_id', $requestId)->first();
            if ($existing !== null) {
                return ['template' => $existing->load($this->displayRelations(includeEditData: true)), 'created' => false];
            }
        }
        try {
            return ['template' => $this->save(new CropActivityTemplate, $data), 'created' => true];
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $requestId === null ? null : CropActivityTemplate::query()->where('creation_request_id', $requestId)->first();
            if ($existing === null) {
                throw $exception;
            }

            return ['template' => $existing->load($this->displayRelations(includeEditData: true)), 'created' => false];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function save(CropActivityTemplate $template, array $data): CropActivityTemplate
    {
        $templateId = $template->exists ? $template->id : null;

        return DB::transaction(function () use ($templateId, $data): CropActivityTemplate {
            $template = $templateId === null ? new CropActivityTemplate : CropActivityTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $this->guardVersion($template, $data['expected_version'] ?? null);
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
                $template->creation_request_id = $data['creation_request_id'] ?? null;
            } else {
                $template->version = (int) $template->version + 1;
            }
            $this->normalizeSchedule($template, $data);
            if (array_intersect(self::SCHEDULE_FIELDS, array_keys($data)) !== []) {
                $template->scheduling_reconfirmation_required = false;
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

    /**
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function changeStatus(CropActivityTemplate $template, bool $isActive, ?int $expectedVersion = null): CropActivityTemplate
    {
        return DB::transaction(function () use ($template, $isActive, $expectedVersion): CropActivityTemplate {
            $template = CropActivityTemplate::query()->lockForUpdate()->findOrFail($template->id);
            $this->guardVersion($template, $expectedVersion);
            $template->is_active = $isActive;
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
            if ($template->isDirty('is_active')) {
                $template->version = (int) $template->version + 1;
            }
            $template->save();
            $template->setAttribute('potential_overlap_ids', $template->is_active ? $template->potentialOverlapIds($regionIds) : []);

            return $template->load($this->displayRelations(includeEditData: true));
        }, attempts: 3);
    }

    /** Delete an unused inactive template; returns false when it is active or referenced. */
    public function delete(CropActivityTemplate $template): bool
    {
        return DB::transaction(function () use ($template): bool {
            $template = CropActivityTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->is_active || $template->hasExternalReferences()) {
                return false;
            }
            $template->delete();

            return true;
        }, attempts: 3);
    }

    /**
     * Flag fixed-offset templates for scheduling reconfirmation after their stage timing changes.
     * The derived flag does not change the template's edit version.
     *
     * @param  list<int>  $stageIds
     */
    public function requireReconfirmationForStages(array $stageIds): void
    {
        $stageIds = array_values(array_unique(array_filter($stageIds)));
        if ($stageIds === []) {
            return;
        }
        CropActivityTemplate::query()->whereIn('crop_stage_id', $stageIds)
            ->where('schedule_basis', 'fixed_offsets')->where('schedule_type', 'after_sowing')
            ->where('scheduling_reconfirmation_required', false)
            ->toBase()->update(['scheduling_reconfirmation_required' => true]);
    }

    /** @return array<int|string, mixed> */
    public function displayRelations(bool $includeEditData = false): array
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

    /**
     * Growth-stage and pending schedules never keep fixed offsets; pending drafts have no schedule type.
     *
     * @param  array<string, mixed>  $data
     */
    private function normalizeSchedule(CropActivityTemplate $template, array $data): void
    {
        $basis = $data['schedule_basis'] ?? $template->schedule_basis;
        if ($basis === 'growth_stage' || $basis === CropActivityTemplate::PENDING_SCHEDULE_BASIS) {
            $template->start_offset_days = null;
            $template->end_offset_days = null;
        }
        if ($basis === CropActivityTemplate::PENDING_SCHEDULE_BASIS) {
            $template->schedule_type = null;
        }
    }

    private function guardVersion(CropActivityTemplate $template, mixed $expectedVersion): void
    {
        if ($expectedVersion !== null && $template->exists && (int) $expectedVersion !== (int) $template->version) {
            throw new ConflictHttpException(self::VERSION_CONFLICT_MESSAGE);
        }
    }
}

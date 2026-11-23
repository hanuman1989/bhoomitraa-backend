<?php

namespace App\Models;

use Database\Factories\CropActivityTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CropActivityTemplate extends Model
{
    /** @use HasFactory<CropActivityTemplateFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    /** @var list<string> */
    public const SCHEDULE_TYPES = ['before_sowing', 'after_sowing', 'after_harvesting'];

    /** @var list<string> */
    public const REGION_SCOPES = ['all', 'specific'];

    public const VARIETY_SCOPES = ['all', 'specific'];

    public const SEASON_SCOPES = ['all', 'specific'];

    /** "pending" is an inactive draft whose scheduling is configured later. */
    public const SCHEDULE_BASES = ['fixed_offsets', 'growth_stage', 'pending'];

    public const PENDING_SCHEDULE_BASIS = 'pending';

    public const MAX_DISPLAY_ORDER = 4294967295;

    public const MAX_TIMING_EXCEPTION_REASON_LENGTH = 2000;

    public const ACTIVITY_TYPES = ['information', 'observation', 'task'];

    public const TRIGGER_TYPES = ['scheduled'];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /** The signed INTEGER storage limit, rather than an agronomic restriction. */
    public const MAX_OFFSET_DAYS = 2147483647;

    /** @var list<string> */
    protected $fillable = [
        'crop_id', 'category_id', 'crop_stage_id', 'variety_scope', 'season_scope', 'region_scope',
        'schedule_type', 'schedule_basis', 'start_offset_days', 'end_offset_days', 'activity_type',
        'allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes',
        'allow_attachments', 'allow_rescheduling', 'smart_actions', 'trigger_type', 'trigger_config',
        'priority', 'is_active', 'timing_exception_reason', 'display_order',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'variety_scope' => 'all', 'season_scope' => 'all', 'schedule_basis' => 'fixed_offsets',
        'activity_type' => 'task', 'allow_status_update' => true, 'allow_expenses' => false,
        'allow_labour' => false, 'allow_notes' => true, 'allow_attachments' => false,
        'allow_rescheduling' => false, 'trigger_type' => 'scheduled',
        'priority' => 'medium', 'is_active' => false,
        'scheduling_reconfirmation_required' => false, 'display_order' => 0, 'version' => 1,
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crop_id' => 'integer', 'category_id' => 'integer', 'crop_stage_id' => 'integer',
            'start_offset_days' => 'integer', 'end_offset_days' => 'integer',
            'allow_status_update' => 'boolean', 'allow_expenses' => 'boolean', 'allow_labour' => 'boolean',
            'allow_notes' => 'boolean', 'allow_attachments' => 'boolean', 'allow_rescheduling' => 'boolean',
            'smart_actions' => 'array', 'trigger_config' => 'array', 'is_active' => 'boolean',
            'scheduling_reconfirmation_required' => 'boolean', 'display_order' => 'integer', 'version' => 'integer',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'category_id');
    }

    public function cropStage(): BelongsTo
    {
        return $this->belongsTo(CropStage::class);
    }

    public function varieties(): BelongsToMany
    {
        return $this->belongsToMany(CropVariety::class, 'crop_activity_template_varieties', 'crop_activity_template_id', 'crop_variety_id');
    }

    public function seasons(): BelongsToMany
    {
        return $this->belongsToMany(Season::class, 'crop_activity_template_seasons', 'crop_activity_template_id', 'season_id');
    }

    public function knowledgeSources(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeSource::class, 'crop_activity_template_sources', 'crop_activity_template_id', 'source_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropActivityTemplateTranslation::class);
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'crop_activity_template_regions', 'crop_activity_template_id', 'region_id');
    }

    public function translation(?string $locale = null): ?CropActivityTemplateTranslation
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }

    public function scopeWithLocalizedTitle(Builder $query): void
    {
        $query->leftJoin('crop_activity_template_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.crop_activity_template_id', '=', 'crop_activity_templates.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('crop_activity_template_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.crop_activity_template_id', '=', 'crop_activity_templates.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('crop_activity_templates.*')
            ->selectRaw('COALESCE(requested_translation.title, default_translation.title) as localized_title');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function resolveRegionIds(array $input): array
    {
        if (array_key_exists('region_ids', $input)) {
            return array_values(array_map('intval', $input['region_ids']));
        }
        if (($input['region_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }
        if (($input['region_scope'] ?? null) === 'specific' && $this->region_scope !== 'specific') {
            return [];
        }

        $this->loadMissing('regions');

        return array_map('intval', $this->regions->modelKeys());
    }

    /** @param array<string, mixed> $input @return list<int> */
    public function resolveVarietyIds(array $input): array
    {
        if (array_key_exists('crop_variety_ids', $input)) {
            return array_values(array_map('intval', $input['crop_variety_ids']));
        }
        if (($input['variety_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }
        $this->loadMissing('varieties');

        return array_map('intval', $this->varieties->modelKeys());
    }

    /** @param array<string, mixed> $input @return list<int> */
    public function resolveSeasonIds(array $input): array
    {
        if (array_key_exists('season_ids', $input)) {
            return array_values(array_map('intval', $input['season_ids']));
        }
        if (($input['season_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }
        $this->loadMissing('seasons');

        return array_map('intval', $this->seasons->modelKeys());
    }

    /** @param array<string, mixed> $input @return list<int> */
    public function resolveKnowledgeSourceIds(array $input): array
    {
        if (array_key_exists('knowledge_source_ids', $input)) {
            return array_values(array_map('intval', $input['knowledge_source_ids']));
        }
        if (! $this->exists) {
            return [];
        }
        $this->loadMissing('knowledgeSources');

        return array_map('intval', $this->knowledgeSources->modelKeys());
    }

    /**
     * Validate effective values and repeat under parent locks before persistence.
     *
     * @param  list<int>  $regionIds
     * @param  array<string, array{title?: string, description?: string|null, instructions?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function validationErrors(
        array $regionIds,
        array $translations = [],
        bool $lockRelationships = false,
        array $varietyIds = [],
        array $seasonIds = [],
        array $knowledgeSourceIds = [],
    ): array {
        $errors = [];
        if ($this->exists && $this->isDirty('crop_id')) {
            $errors['crop_id'] = ['The parent crop cannot be changed after creation.'];
        }
        $isPending = $this->schedule_basis === self::PENDING_SCHEDULE_BASIS;
        if (! $isPending && ! in_array($this->schedule_type, self::SCHEDULE_TYPES, true)) {
            $errors['schedule_type'] = ['Select a supported activity schedule.'];
        }
        if (! in_array($this->schedule_basis, self::SCHEDULE_BASES, true)) {
            $errors['schedule_basis'] = ['Select a supported schedule basis.'];
        } elseif ($isPending) {
            if ($this->is_active) {
                $errors['schedule_basis'] = ['Configure scheduling before activating this template.'];
            } elseif ($this->schedule_type !== null || $this->start_offset_days !== null || $this->end_offset_days !== null) {
                $errors['schedule_basis'] = ['Pending drafts cannot store a schedule type or offsets.'];
            }
        } elseif ($this->schedule_basis === 'fixed_offsets') {
            foreach (['start_offset_days', 'end_offset_days'] as $field) {
                if ($this->{$field} === null || $this->{$field} < 0 || $this->{$field} > self::MAX_OFFSET_DAYS) {
                    $errors[$field] = ['Offsets must be integers between 0 and '.self::MAX_OFFSET_DAYS.'.'];
                }
            }
            if (! $errors && $this->start_offset_days > $this->end_offset_days) {
                $errors['end_offset_days'] = ['The end offset must be greater than or equal to the start offset.'];
            }
        } elseif ($this->schedule_basis === 'growth_stage') {
            if ($this->schedule_type !== 'after_sowing') {
                $errors['schedule_type'] = ['Growth-stage scheduling is supported only after sowing.'];
            }
            if ($this->crop_stage_id === null) {
                $errors['crop_stage_id'] = ['A growth stage is required for growth-stage scheduling.'];
            }
            if ($this->start_offset_days !== null || $this->end_offset_days !== null) {
                $errors['start_offset_days'] = ['Growth-stage scheduling does not accept fixed offsets.'];
                $errors['end_offset_days'] = ['Growth-stage scheduling does not accept fixed offsets.'];
            }
        }
        if (! in_array($this->activity_type, self::ACTIVITY_TYPES, true)) {
            $errors['activity_type'] = ['Select a supported activity type.'];
        } elseif ($this->activity_type === 'information') {
            foreach (['allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes', 'allow_attachments', 'allow_rescheduling'] as $field) {
                if ($this->{$field}) {
                    $errors[$field] = ['Information activities cannot enable standard management features.'];
                }
            }
        } elseif ($this->activity_type === 'observation' && $this->allow_status_update) {
            $errors['allow_status_update'] = ['Observation activities cannot enable status updates.'];
        }
        if (! in_array($this->trigger_type, self::TRIGGER_TYPES, true)) {
            $errors['trigger_type'] = ['Only scheduled triggers are supported.'];
        }
        if ($this->trigger_config !== null && $this->trigger_config !== []) {
            $errors['trigger_config'] = ['Trigger configuration is not editable for scheduled templates.'];
        }
        foreach (array_values($this->smart_actions ?? []) as $action) {
            if (! is_string($action) || ! in_array($action, config('crop_activity_templates.smart_actions', []), true)) {
                $errors['smart_actions'] = ['Select only supported smart action identifiers.'];
                break;
            }
        }
        if (count($this->smart_actions ?? []) !== count(array_unique($this->smart_actions ?? []))) {
            $errors['smart_actions'] = ['Smart action identifiers must not be duplicated.'];
        }
        if (! in_array($this->priority, self::PRIORITIES, true)) {
            $errors['priority'] = ['Select a valid activity priority.'];
        }

        $related = [];
        foreach ([
            'crop_id' => Crop::class, 'category_id' => ActivityCategory::class,
            'crop_stage_id' => CropStage::class,
        ] as $field => $model) {
            if ($this->{$field} === null && $field === 'crop_stage_id') {
                continue;
            }
            $record = $model::query()->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->{$field});
            $related[$field] = $record;
            if ($record === null) {
                $errors[$field] = ['Select an existing related record.'];

                continue;
            }
            if ($this->is_active && ! $record->is_active) {
                $errors[$field] = ['An active template requires this related record to be active.'];
            }
            if ($field === 'crop_stage_id' && (int) $record->crop_id !== $this->crop_id) {
                $errors[$field] = ['The selected record must belong to the template crop.'];
            }
        }
        $category = $related['category_id'] ?? null;
        if ($category !== null && ($this->is_active || ! $this->exists || $this->isDirty('category_id'))
            && (! $category->is_active || $category->activationErrors() !== [])) {
            $errors['category_id'] = ['Select an active, released activity category with its required names.'];
        }
        $relationshipErrors = $this->applicabilityErrors($varietyIds, $seasonIds, $knowledgeSourceIds, $lockRelationships);

        return [...$errors, ...$relationshipErrors, ...$this->regionErrors($regionIds, $lockRelationships), ...$this->contentErrors($translations)];
    }

    /** @param list<int> $varietyIds @param list<int> $seasonIds @param list<int> $knowledgeSourceIds @return array<string,list<string>> */
    private function applicabilityErrors(array $varietyIds, array $seasonIds, array $knowledgeSourceIds, bool $lockRelationships): array
    {
        $errors = [];
        if (! in_array($this->variety_scope, self::VARIETY_SCOPES, true)) {
            $errors['variety_scope'] = ['The variety scope must be all or specific.'];
        } elseif ($this->variety_scope === 'all' && $varietyIds !== []) {
            $errors['crop_variety_ids'] = ['All-varieties templates cannot contain variety selections.'];
        } elseif ($this->variety_scope === 'specific' && $varietyIds === []) {
            $errors['crop_variety_ids'] = ['Select at least one crop variety.'];
        }
        $varieties = CropVariety::query()->whereIn('id', $varietyIds)->orderBy('id')
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'crop_id', 'is_active'])->keyBy('id');
        foreach ($varietyIds as $index => $id) {
            $variety = $varieties->get($id);
            if ($variety === null) {
                $errors['crop_variety_ids.'.$index] = ['Select an existing crop variety.'];
            } elseif ((int) $variety->crop_id !== $this->crop_id) {
                $errors['crop_variety_ids.'.$index] = ['The selected variety must belong to the template crop.'];
            } elseif ($this->is_active && ! $variety->is_active) {
                $errors['crop_variety_ids.'.$index] = ['An active template requires active varieties.'];
            }
        }

        if (! in_array($this->season_scope, self::SEASON_SCOPES, true)) {
            $errors['season_scope'] = ['The season scope must be all or specific.'];
        } elseif ($this->season_scope === 'all' && $seasonIds !== []) {
            $errors['season_ids'] = ['All-seasons templates cannot contain season selections.'];
        } elseif ($this->season_scope === 'specific' && $seasonIds === []) {
            $errors['season_ids'] = ['Select at least one season.'];
        }
        $seasons = Season::query()->whereIn('id', $seasonIds)->orderBy('id')
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'is_active'])->keyBy('id');
        foreach ($seasonIds as $index => $id) {
            $season = $seasons->get($id);
            if ($season === null) {
                $errors['season_ids.'.$index] = ['Select an existing season.'];
            } elseif ($this->is_active && ! $season->is_active) {
                $errors['season_ids.'.$index] = ['An active template requires active seasons.'];
            }
        }

        $sources = KnowledgeSource::query()->whereIn('id', $knowledgeSourceIds)->orderBy('id')
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get()->keyBy('id');
        foreach ($knowledgeSourceIds as $index => $id) {
            $source = $sources->get($id);
            if ($source === null) {
                $errors['knowledge_source_ids.'.$index] = ['Select an existing knowledge source.'];
            } elseif ($this->is_active && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference())) {
                $errors['knowledge_source_ids.'.$index] = ['Published templates require active, verified knowledge sources with usable references.'];
            }
        }

        return $errors;
    }

    /**
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    private function regionErrors(array $regionIds, bool $lockRelationships): array
    {
        $errors = [];
        if (! in_array($this->region_scope, self::REGION_SCOPES, true)) {
            $errors['region_scope'] = ['The region scope must be all or specific.'];
        } elseif ($this->region_scope === 'all' && $regionIds !== []) {
            $errors['region_ids'] = ['All-regions templates cannot contain district selections.'];
        } elseif ($this->region_scope === 'specific' && $regionIds === []) {
            $errors['region_ids'] = ['Select at least one district.'];
        }
        $existingIds = [];
        if ($this->exists) {
            $this->loadMissing('regions');
            $existingIds = array_map('intval', $this->regions->modelKeys());
        }
        $regions = Region::query()->whereIn('id', $regionIds)->orderBy('id')
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'region_type', 'is_active'])->keyBy('id');
        foreach ($regionIds as $index => $id) {
            $region = $regions->get($id);
            if ($region === null || $region->region_type !== 'district') {
                $errors['region_ids.'.$index] = ['Select an existing district.'];
            } elseif (! $region->is_active && ($this->is_active || ! in_array($id, $existingIds, true))) {
                $errors['region_ids.'.$index] = ['Newly selected districts and published district selections must be active.'];
            }
        }

        return $errors;
    }

    /**
     * Presence is enforceable here; scientific relevance still requires the existing reviewer.
     *
     * @param  array<string, array{title?: string, description?: string|null, instructions?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    private function contentErrors(array $translations): array
    {
        $this->loadMissing('translations');
        $values = $this->translations->mapWithKeys(fn (CropActivityTemplateTranslation $translation): array => [
            $translation->locale => $translation->only(['title', 'description', 'instructions']),
        ])->all();
        foreach ($translations as $locale => $fields) {
            $values[$locale] = array_replace($values[$locale] ?? [], $fields);
        }
        $errors = [];
        foreach (array_unique([...self::REQUIRED_LOCALES, ...array_keys($values)]) as $locale) {
            if (blank($values[$locale]['title'] ?? null)) {
                $errors['translations.'.$locale.'.title'] = ['A nonempty '.$locale.' activity title is required.'];
            }
            if ($this->is_active && array_key_exists($locale, config('languages.supported')) && blank($values[$locale]['instructions'] ?? null)) {
                $errors['translations.'.$locale.'.instructions'] = ['Farmer-facing instructions are required for each published translation.'];
            }
        }

        return $errors;
    }

    /**
     * Potential intersections are advisory: no template precedence policy is defined yet.
     *
     * Fixed-offset templates intersect by day window (same stage or stage-less).
     * Growth-stage templates have no offsets of their own, so they intersect
     * with every active template in the same stage; a fixed-offset template
     * also reports growth-stage templates of its stage. Stage-less templates
     * are not compared with growth-stage ones because stage day windows vary
     * by region timeline.
     *
     * @param  list<int>  $regionIds
     * @return list<int>
     */
    public function potentialOverlapIds(array $regionIds): array
    {
        $query = self::query()->where('is_active', true)->where('crop_id', $this->crop_id)
            ->where('category_id', $this->category_id)
            ->where('schedule_type', $this->schedule_type)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id));
        $stageId = $this->crop_stage_id;
        if ($this->start_offset_days === null || $this->end_offset_days === null) {
            if ($stageId === null) {
                return [];
            }
            $query->where('crop_stage_id', $stageId);
        } else {
            $query->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $window): Builder => $window
                    ->where('start_offset_days', '<=', $this->end_offset_days)
                    ->where('end_offset_days', '>=', $this->start_offset_days)
                    ->when($stageId !== null, fn (Builder $window): Builder => $window
                        ->where(fn (Builder $stage): Builder => $stage->whereNull('crop_stage_id')->orWhere('crop_stage_id', $stageId))))
                ->when($stageId !== null, fn (Builder $query): Builder => $query
                    ->orWhere(fn (Builder $stage): Builder => $stage
                        ->where('schedule_basis', 'growth_stage')->where('crop_stage_id', $stageId))));
        }
        if ($this->region_scope === 'specific') {
            $query->where(fn (Builder $query): Builder => $query->where('region_scope', 'all')
                ->orWhereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds)));
        }

        return $query->orderBy('id')->limit(20)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** Protect actual historical references, even when their foreign key permits cascades. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], [
                'crop_activity_template_translations', 'crop_activity_template_regions',
                'crop_activity_template_varieties', 'crop_activity_template_seasons', 'crop_activity_template_sources',
            ], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== $this->getTable()) {
                    continue;
                }
                $position = array_search('id', $foreignKey['foreign_columns'] ?? [], true);
                if ($position !== false && DB::table($table['name'])->where($foreignKey['columns'][$position], $this->getKey())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

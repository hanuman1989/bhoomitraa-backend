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
    public const SCHEDULE_TYPES = ['after_sowing'];

    /** @var list<string> */
    public const REGION_SCOPES = ['all', 'specific'];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high'];

    /** The signed INTEGER storage limit, rather than an agronomic restriction. */
    public const MAX_OFFSET_DAYS = 2147483647;

    /** @var list<string> */
    protected $fillable = [
        'crop_id', 'crop_variety_id', 'category_id', 'crop_stage_id', 'season_id',
        'schedule_type', 'start_offset_days', 'end_offset_days', 'region_scope', 'priority', 'source_id', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['priority' => 'medium', 'is_active' => false];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crop_id' => 'integer', 'crop_variety_id' => 'integer', 'category_id' => 'integer',
            'crop_stage_id' => 'integer', 'season_id' => 'integer', 'source_id' => 'integer',
            'start_offset_days' => 'integer', 'end_offset_days' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ActivityCategory::class, 'category_id');
    }

    public function cropStage(): BelongsTo
    {
        return $this->belongsTo(CropStage::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
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

    /**
     * Validate effective values and repeat under parent locks before persistence.
     *
     * @param  list<int>  $regionIds
     * @param  array<string, array{title?: string, description?: string|null, instructions?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function validationErrors(array $regionIds, array $translations = [], bool $lockRelationships = false): array
    {
        $errors = [];
        if ($this->exists && $this->isDirty('crop_id')) {
            $errors['crop_id'] = ['The parent crop cannot be changed after creation.'];
        }
        if (! in_array($this->schedule_type, self::SCHEDULE_TYPES, true)) {
            $errors['schedule_type'] = ['Only after_sowing scheduling is supported.'];
        }
        foreach (['start_offset_days', 'end_offset_days'] as $field) {
            if ($this->{$field} === null || $this->{$field} < 0 || $this->{$field} > self::MAX_OFFSET_DAYS) {
                $errors[$field] = ['Offsets must be integers between 0 and '.self::MAX_OFFSET_DAYS.'.'];
            }
        }
        if ($this->start_offset_days > $this->end_offset_days) {
            $errors['end_offset_days'] = ['The end offset must be greater than or equal to the start offset.'];
        }
        if (! in_array($this->priority, self::PRIORITIES, true)) {
            $errors['priority'] = ['Select a valid activity priority.'];
        }

        $related = [];
        foreach ([
            'crop_id' => Crop::class, 'crop_variety_id' => CropVariety::class, 'category_id' => ActivityCategory::class,
            'crop_stage_id' => CropStage::class, 'season_id' => Season::class, 'source_id' => KnowledgeSource::class,
        ] as $field => $model) {
            if ($this->{$field} === null && in_array($field, ['crop_variety_id', 'crop_stage_id'], true)) {
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
            if (in_array($field, ['crop_variety_id', 'crop_stage_id'], true) && (int) $record->crop_id !== $this->crop_id) {
                $errors[$field] = ['The selected record must belong to the template crop.'];
            }
        }
        $category = $related['category_id'] ?? null;
        if ($category !== null && ($this->is_active || ! $this->exists || $this->isDirty('category_id'))
            && (! $category->is_active || $category->activationErrors() !== [])) {
            $errors['category_id'] = ['Select an active, released activity category with its required names.'];
        }
        $source = $related['source_id'] ?? null;
        if ($this->is_active && $source !== null
            && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference())) {
            $errors['source_id'] = ['Publication requires an active, verified knowledge source with a usable reference.'];
        }

        return [...$errors, ...$this->regionErrors($regionIds, $lockRelationships), ...$this->contentErrors($translations)];
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
     * @param  list<int>  $regionIds
     * @return list<int>
     */
    public function potentialOverlapIds(array $regionIds): array
    {
        $query = self::query()->where('is_active', true)->where('crop_id', $this->crop_id)
            ->where('category_id', $this->category_id)->where('season_id', $this->season_id)
            ->where('schedule_type', $this->schedule_type)
            ->where('start_offset_days', '<=', $this->end_offset_days)->where('end_offset_days', '>=', $this->start_offset_days)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id));
        foreach (['crop_variety_id', 'crop_stage_id'] as $field) {
            if ($this->{$field} !== null) {
                $query->where(fn (Builder $query): Builder => $query->whereNull($field)->orWhere($field, $this->{$field}));
            }
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
            if (in_array($table['name'], ['crop_activity_template_translations', 'crop_activity_template_regions'], true)) {
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

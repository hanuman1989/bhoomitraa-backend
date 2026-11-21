<?php

namespace App\Models;

use Database\Factories\CropStageTimelineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CropStageTimeline extends Model
{
    /** @use HasFactory<CropStageTimelineFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REGION_SCOPES = ['all', 'specific'];

    public const VARIETY_SCOPES = ['all', 'specific'];

    public const SEASON_SCOPES = ['all', 'specific'];

    /** @var list<string> */
    protected $fillable = [
        'crop_stage_id', 'variety_scope', 'season_scope', 'region_scope',
        'days_after_sowing_min', 'days_after_sowing_max', 'is_active',
    ];

    protected $attributes = ['variety_scope' => 'all', 'season_scope' => 'all'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crop_stage_id' => 'integer', 'days_after_sowing_min' => 'integer',
            'days_after_sowing_max' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function cropStage(): BelongsTo
    {
        return $this->belongsTo(CropStage::class);
    }

    public function varieties(): BelongsToMany
    {
        return $this->belongsToMany(CropVariety::class, 'crop_stage_timeline_varieties', 'crop_stage_timeline_id', 'crop_variety_id');
    }

    public function seasons(): BelongsToMany
    {
        return $this->belongsToMany(Season::class, 'crop_stage_timeline_seasons', 'crop_stage_timeline_id', 'season_id');
    }

    public function knowledgeSources(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeSource::class, 'crop_stage_timeline_sources', 'crop_stage_timeline_id', 'source_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'crop_stage_timeline_regions', 'crop_stage_timeline_id', 'region_id');
    }

    /**
     * Omitted selections survive PATCH; an explicit switch to all clears coverage.
     *
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

        return $this->regions()->orderBy('regions.id')->pluck('regions.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @param array<string,mixed> $input @return list<int> */
    public function resolveVarietyIds(array $input): array
    {
        if (array_key_exists('crop_variety_ids', $input)) {
            return array_values(array_map('intval', $input['crop_variety_ids']));
        }
        if (($input['variety_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }

        return $this->varieties()->orderBy('crop_varieties.id')->pluck('crop_varieties.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @param array<string,mixed> $input @return list<int> */
    public function resolveSeasonIds(array $input): array
    {
        if (array_key_exists('season_ids', $input)) {
            return array_values(array_map('intval', $input['season_ids']));
        }
        if (($input['season_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }

        return $this->seasons()->orderBy('seasons.id')->pluck('seasons.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @param array<string,mixed> $input @return list<int> */
    public function resolveKnowledgeSourceIds(array $input): array
    {
        if (array_key_exists('knowledge_source_ids', $input)) {
            return array_values(array_map('intval', $input['knowledge_source_ids']));
        }
        if (! $this->exists) {
            return [];
        }

        return $this->knowledgeSources()->orderBy('knowledge_sources.id')->pluck('knowledge_sources.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Check effective values, then repeat under locks before related writes.
     *
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    public function validationErrors(
        array $regionIds,
        bool $lockRelationships = false,
        array $varietyIds = [],
        array $seasonIds = [],
        array $knowledgeSourceIds = [],
    ): array {
        $errors = [];
        foreach (['days_after_sowing_min', 'days_after_sowing_max'] as $field) {
            if ($this->{$field} === null || $this->{$field} < 0 || $this->{$field} > 4294967295) {
                $errors[$field] = ['Days after sowing must be between 0 and 4294967295.'];
            }
        }
        if ($this->days_after_sowing_min > $this->days_after_sowing_max) {
            $errors['days_after_sowing_max'] = ['The maximum days must be greater than or equal to the minimum days.'];
        }

        if (! in_array($this->region_scope, self::REGION_SCOPES, true)) {
            $errors['region_scope'] = ['The region scope must be all or specific.'];
        } elseif ($this->region_scope === 'all' && $regionIds !== []) {
            $errors['region_ids'] = ['All-regions timelines cannot contain district selections.'];
        } elseif ($this->region_scope === 'specific' && $regionIds === []) {
            $errors['region_ids'] = ['Select at least one district.'];
        }

        $stage = CropStage::query()->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->crop_stage_id);
        $crop = $stage === null ? null : Crop::query()
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($stage->crop_id);
        if ($stage === null || $crop === null) {
            $errors['crop_stage_id'] = ['Select a growth stage belonging to an existing crop.'];
        } elseif ($this->is_active && (! $stage->is_active || ! $crop->is_active)) {
            $errors['crop_stage_id'] = ['An active timeline requires an active growth stage and parent crop.'];
        }

        $regions = Region::query()->whereIn('id', $regionIds)->orderBy('id')
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'region_type', 'is_active'])->keyBy('id');
        foreach ($regionIds as $index => $id) {
            $region = $regions->get($id);
            if ($region === null || $region->region_type !== 'district') {
                $errors['region_ids.'.$index] = ['Select an existing district.'];
            } elseif ($this->is_active && ! $region->is_active) {
                $errors['region_ids.'.$index] = ['Selected districts must be active before publication.'];
            }
        }

        if (! in_array($this->variety_scope, self::VARIETY_SCOPES, true)) {
            $errors['variety_scope'] = ['The variety scope must be all or specific.'];
        } elseif ($this->variety_scope === 'all' && $varietyIds !== []) {
            $errors['crop_variety_ids'] = ['All-varieties timelines cannot contain variety selections.'];
        } elseif ($this->variety_scope === 'specific' && $varietyIds === []) {
            $errors['crop_variety_ids'] = ['Select at least one crop variety.'];
        }
        $varieties = CropVariety::query()->whereIn('id', $varietyIds)
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'crop_id', 'is_active'])->keyBy('id');
        foreach ($varietyIds as $index => $id) {
            $variety = $varieties->get($id);
            if ($variety === null) {
                $errors['crop_variety_ids.'.$index] = ['Select an existing crop variety.'];
            } elseif ($crop === null || (int) $variety->crop_id !== $crop->id) {
                $errors['crop_variety_ids.'.$index] = ['The variety must belong to the same crop as the selected growth stage.'];
            } elseif ($this->is_active && ! $variety->is_active) {
                $errors['crop_variety_ids.'.$index] = ['An active timeline requires active varieties.'];
            }
        }

        if (! in_array($this->season_scope, self::SEASON_SCOPES, true)) {
            $errors['season_scope'] = ['The season scope must be all or specific.'];
        } elseif ($this->season_scope === 'all' && $seasonIds !== []) {
            $errors['season_ids'] = ['All-seasons timelines cannot contain season selections.'];
        } elseif ($this->season_scope === 'specific' && $seasonIds === []) {
            $errors['season_ids'] = ['Select at least one season.'];
        }
        $seasons = Season::query()->whereIn('id', $seasonIds)
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'is_active'])->keyBy('id');
        foreach ($seasonIds as $index => $id) {
            $season = $seasons->get($id);
            if ($season === null) {
                $errors['season_ids.'.$index] = ['Select an existing season.'];
            } elseif ($this->is_active && ! $season->is_active) {
                $errors['season_ids.'.$index] = ['An active timeline requires active seasons.'];
            }
        }

        $sources = KnowledgeSource::query()->whereIn('id', $knowledgeSourceIds)
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get()->keyBy('id');
        foreach ($knowledgeSourceIds as $index => $id) {
            $source = $sources->get($id);
            if ($source === null) {
                $errors['knowledge_source_ids.'.$index] = ['Select an existing knowledge source.'];
            } elseif ($this->is_active && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference())) {
                $errors['knowledge_source_ids.'.$index] = ['Published timelines require active, verified knowledge sources with usable references.'];
            }
        }

        return $errors;
    }

    /**
     * A null variety/season or all-region coverage intersects every restriction.
     * Different day ranges still compete for the same applicability.
     *
     * @param  list<int>  $regionIds
     */
    public function overlappingActiveTimeline(array $regionIds, bool $lock = false): ?self
    {
        $query = self::query()->where('crop_stage_id', $this->crop_stage_id)->where('is_active', true)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id));
        $varietyIds = $this->resolveVarietyIds([]);
        if ($this->variety_scope === 'specific') {
            $query->where(fn (Builder $query): Builder => $query
                ->where('variety_scope', 'all')
                ->orWhereHas('varieties', fn (Builder $varieties): Builder => $varieties->whereIn('crop_varieties.id', $varietyIds)));
        }
        $seasonIds = $this->resolveSeasonIds([]);
        if ($this->season_scope === 'specific') {
            $query->where(fn (Builder $query): Builder => $query
                ->where('season_scope', 'all')
                ->orWhereHas('seasons', fn (Builder $seasons): Builder => $seasons->whereIn('seasons.id', $seasonIds)));
        }
        if ($this->region_scope === 'specific') {
            $query->where(fn (Builder $query): Builder => $query->where('region_scope', 'all')
                ->orWhereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds)));
        }

        return $query->orderBy('id')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first(['id']);
    }

    /** Inspect actual dependencies, including cascades, so history is never removed. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], [
                'crop_stage_timeline_regions',
                'crop_stage_timeline_varieties',
                'crop_stage_timeline_seasons',
                'crop_stage_timeline_sources',
            ], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                $columns = $foreignKey['columns'] ?? [];
                if (($foreignKey['foreign_table'] ?? null) !== $this->getTable()
                    || ($foreignKey['foreign_columns'] ?? []) !== ['id'] || count($columns) !== 1) {
                    continue;
                }
                if (DB::table($table['name'])->where($columns[0], $this->getKey())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

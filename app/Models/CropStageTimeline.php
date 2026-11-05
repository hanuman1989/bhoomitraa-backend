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

    /** @var list<string> */
    protected $fillable = [
        'crop_stage_id', 'crop_variety_id', 'season_id', 'region_scope',
        'days_after_sowing_min', 'days_after_sowing_max', 'source_id', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crop_stage_id' => 'integer', 'crop_variety_id' => 'integer', 'season_id' => 'integer',
            'source_id' => 'integer', 'days_after_sowing_min' => 'integer',
            'days_after_sowing_max' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function cropStage(): BelongsTo
    {
        return $this->belongsTo(CropStage::class);
    }

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
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

    /**
     * Check effective values, then repeat under locks before related writes.
     *
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    public function validationErrors(array $regionIds, bool $lockRelationships = false): array
    {
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

        if ($this->crop_variety_id !== null) {
            $variety = CropVariety::query()
                ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->crop_variety_id);
            if ($variety === null || $crop === null || $variety->crop_id !== $crop->id) {
                $errors['crop_variety_id'] = ['The variety must belong to the same crop as the selected growth stage.'];
            } elseif ($this->is_active && ! $variety->is_active) {
                $errors['crop_variety_id'] = ['An active timeline requires an active variety when one is selected.'];
            }
        }

        if ($this->season_id !== null) {
            $season = Season::query()->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->season_id);
            if ($season === null || ($this->is_active && ! $season->is_active)) {
                $errors['season_id'] = ['Select an existing season that is active before publishing the timeline.'];
            }
        }

        $source = KnowledgeSource::query()
            ->when($lockRelationships, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->source_id);
        if ($source === null) {
            $errors['source_id'] = ['Select an existing knowledge source.'];
        } elseif ($this->is_active && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference())) {
            $errors['source_id'] = ['Publication requires an active, verified knowledge source with a usable reference.'];
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
        foreach (['crop_variety_id', 'season_id'] as $field) {
            if ($this->{$field} !== null) {
                $query->where(fn (Builder $query): Builder => $query->whereNull($field)->orWhere($field, $this->{$field}));
            }
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
            if ($table['name'] === 'crop_stage_timeline_regions') {
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

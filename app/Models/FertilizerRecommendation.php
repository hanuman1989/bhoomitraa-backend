<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\FertilizerRecommendationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FertilizerRecommendation extends Model
{
    /** @use HasFactory<FertilizerRecommendationFactory> */
    use HasFactory;

    public const REGION_SCOPES = CropStageTimeline::REGION_SCOPES;

    public const RECOMMENDATION_TYPES = ['general'];

    public const IRRIGATION_CONDITIONS = CropSeedRate::IRRIGATION_CONDITIONS;

    /** Soil texture only; these codes do not describe pH, fertility or salinity. */
    public const SOIL_CONDITIONS = ['sandy', 'loamy', 'clayey'];

    public const APPLICATION_METHODS = Fertilizer::APPLICATION_METHODS;

    protected $fillable = [
        'crop_id', 'crop_variety_id', 'season_id', 'recommendation_type', 'irrigation_condition', 'soil_condition', 'application_method',
        'reference_code', 'region_scope', 'source_id', 'reference_year', 'basal_dose_notes', 'top_dressing_notes', 'general_advisory', 'notes', 'is_active',
    ];

    protected $attributes = ['is_active' => true];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'crop_id' => 'integer', 'crop_variety_id' => 'integer', 'season_id' => 'integer', 'source_id' => 'integer',
            'reference_year' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function nutrients(): HasMany
    {
        return $this->hasMany(FertilizerRecommendationNutrient::class)->orderBy('id');
    }

    /** @return array<string, list<string>> */
    public function storedNutrientErrors(): array
    {
        $rows = $this->nutrients()->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return ['nutrients' => ['Add at least one nutrient target.']];
        }
        $errors = [];
        foreach ($rows as $index => $row) {
            foreach (['nutrient_code' => FertilizerRecommendationNutrient::CODES, 'unit_code' => FertilizerRecommendationNutrient::unitCodes(),
                'area_unit_code' => FertilizerRecommendationNutrient::areaUnitCodes()] as $field => $allowed) {
                if (! in_array($row->{$field}, $allowed, true)) {
                    $errors['nutrients.'.$index.'.'.$field] = ['Select a supported '.$field.'.'];
                }
            }
            if (BigDecimal::of($row->recommended_quantity)->isLessThanOrEqualTo('0') || BigDecimal::of($row->recommended_quantity)->isGreaterThan('9999999999.9999')) {
                $errors['nutrients.'.$index.'.recommended_quantity'] = ['The nutrient quantity must be positive and fit the supported decimal range.'];
            }
        }

        return $errors;
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'fertilizer_recommendation_regions');
    }

    /**
     * Omitted district selections survive PATCH; switching to all clears mappings explicitly.
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
        $this->loadMissing('regions');

        return array_map('intval', $this->regions->modelKeys());
    }

    /**
     * Revalidate effective values for partial updates and repeat under locks before persistence.
     *
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    public function validationErrors(array $regionIds, bool $lock = false): array
    {
        $errors = [];
        foreach (['recommendation_type' => self::RECOMMENDATION_TYPES, 'irrigation_condition' => self::IRRIGATION_CONDITIONS,
            'soil_condition' => self::SOIL_CONDITIONS, 'application_method' => self::APPLICATION_METHODS] as $field => $allowed) {
            if (($field === 'recommendation_type' || $this->{$field} !== null) && ! in_array($this->{$field}, $allowed, true)) {
                $errors[$field] = ['Select a supported '.$field.'.'];
            }
        }
        if ($this->reference_year !== null && ($this->reference_year < 1000 || $this->reference_year > min(9999, now()->year))) {
            $errors['reference_year'] = ['The reference year must be a four-digit year no later than the current year.'];
        }

        $crop = Crop::query()->whereKey($this->crop_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($crop === null || ((! $this->exists || $this->isDirty('crop_id') || $this->is_active)
            && ! Crop::query()->published()->whereKey($this->crop_id)->exists())) {
            $errors['crop_id'] = ['Select an available published crop.'];
        }
        if ($this->crop_variety_id !== null) {
            $variety = CropVariety::query()->whereKey($this->crop_variety_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
            if ($variety === null || $variety->crop_id !== $this->crop_id) {
                $errors['crop_variety_id'] = ['The selected variety must belong to the selected crop.'];
            } elseif ((! $this->exists || $this->isDirty('crop_variety_id') || $this->is_active) && ! $variety->is_active) {
                $errors['crop_variety_id'] = ['Select an active crop variety.'];
            }
        }
        if ($this->season_id !== null) {
            $season = Season::query()->whereKey($this->season_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
            if ($season === null || ((! $this->exists || $this->isDirty('season_id') || $this->is_active) && ! $season->is_active)) {
                $errors['season_id'] = ['Select an active season.'];
            }
        }
        $source = KnowledgeSource::query()->whereKey($this->source_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($source === null || ((! $this->exists || $this->isDirty('source_id') || $this->is_active)
            && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference()))) {
            $errors['source_id'] = ['Select an active, verified knowledge source with a usable reference.'];
        }

        $this->loadMissing('regions');
        $savedIds = array_map('intval', $this->regions->modelKeys());
        if (! in_array($this->region_scope, self::REGION_SCOPES, true)) {
            $errors['region_scope'] = ['The region scope must be all or specific.'];
        } elseif ($this->region_scope === 'all' && $regionIds !== []) {
            $errors['region_ids'] = ['All-regions recommendations cannot contain district selections.'];
        } elseif ($this->region_scope === 'specific' && $regionIds === []) {
            $errors['region_ids'] = ['Select at least one district.'];
        }
        $regions = Region::query()->whereIn('id', $regionIds)->orderBy('id')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'region_type', 'is_active'])->keyBy('id');
        foreach ($regionIds as $index => $id) {
            $region = $regions->get($id);
            if ($region === null || $region->region_type !== 'district') {
                $errors['region_ids.'.$index] = ['Select an existing district.'];
            } elseif (! $region->is_active && ! in_array($id, $savedIds, true)) {
                $errors['region_ids.'.$index] = ['Inactive districts cannot be newly selected.'];
            }
        }
        $before = $savedIds;
        $after = $regionIds;
        sort($before);
        sort($after);
        if ($this->exists && ($this->isDirty(array_diff($this->getFillable(), ['is_active'])) || $before !== $after) && $this->hasExternalReferences()) {
            $errors['crop_id'] = ['Referenced historical recommendations cannot be rewritten. Deactivate this record and create a new recommendation.'];
        }

        return $errors;
    }

    /**
     * Type, year and source distinguish independent evidence; broader scopes remain valid references.
     * Call after acquiring the parent crop lock, including for first inserts.
     *
     * @param  list<int>  $regionIds
     */
    public function overlappingActiveRecommendation(array $regionIds): ?self
    {
        $query = self::query()->where('is_active', true)->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id));
        foreach (['crop_id', 'crop_variety_id', 'season_id', 'recommendation_type', 'irrigation_condition', 'soil_condition', 'application_method', 'reference_year', 'source_id', 'region_scope'] as $field) {
            $query->where($field, $this->{$field});
        }
        if ($this->region_scope === 'specific') {
            $query->whereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds));
        }

        return $query->orderBy('id')->first(['id']);
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], ['fertilizer_recommendation_nutrients', 'fertilizer_recommendation_regions'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'fertilizer_recommendation_nutrients' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->whereIn($foreignKey['columns'][0], $this->nutrients()->select('id'))->exists()) {
                    return true;
                }
                if (($foreignKey['foreign_table'] ?? null) === 'fertilizer_recommendations' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\CropSeedRateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CropSeedRate extends Model
{
    /** @use HasFactory<CropSeedRateFactory> */
    use HasFactory;

    public const REGION_SCOPES = CropStageTimeline::REGION_SCOPES;

    public const AREA_UNIT_CODES = ['ha', 'acre'];

    public const SOWING_METHODS = ['broadcasting', 'line_sowing', 'drilling', 'transplanting', 'dibbling'];

    public const IRRIGATION_CONDITIONS = ['irrigated', 'rainfed'];

    public const RATE_BASES = ['per_area'];

    protected $fillable = [
        'crop_id', 'crop_variety_id', 'seed_id', 'season_id', 'material_type',
        'sowing_method', 'irrigation_condition', 'rate_basis', 'rate_min', 'rate_recommended',
        'rate_max', 'rate_unit_code', 'area_unit_code', 'spacing_row_cm', 'spacing_plant_cm',
        'region_scope', 'source_id', 'notes', 'is_active',
    ];

    protected $attributes = ['rate_basis' => 'per_area', 'is_active' => false];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'crop_id' => 'integer', 'crop_variety_id' => 'integer', 'seed_id' => 'integer',
            'season_id' => 'integer', 'source_id' => 'integer', 'is_active' => 'boolean',
            'rate_min' => 'decimal:4', 'rate_recommended' => 'decimal:4', 'rate_max' => 'decimal:4',
            'spacing_row_cm' => 'decimal:2', 'spacing_plant_cm' => 'decimal:2',
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

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class)->withTrashed();
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
        return $this->belongsToMany(Region::class, 'crop_seed_rate_regions');
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
     * Validate effective values for partial updates and repeat under locks before persistence.
     *
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    public function validationErrors(array $regionIds, bool $lock = false): array
    {
        $errors = [];
        $recommended = $this->rate_recommended === null ? null : BigDecimal::of($this->rate_recommended);
        foreach (['rate_min', 'rate_recommended', 'rate_max'] as $field) {
            $value = $this->{$field};
            if ($value === null && $field !== 'rate_recommended') {
                continue;
            }
            if ($value === null || BigDecimal::of($value)->isNegative() || BigDecimal::of($value)->isGreaterThan('9999999999.9999')
                || ($field === 'rate_recommended' && BigDecimal::of($value)->isZero())) {
                $errors[$field] = ['Rates must fit the supported decimal range; the recommended rate must be greater than zero.'];
            }
        }
        if ($recommended !== null && $this->rate_min !== null && BigDecimal::of($this->rate_min)->isGreaterThan($recommended)) {
            $errors['rate_min'] = ['The minimum rate must not exceed the recommended rate.'];
        }
        if ($recommended !== null && $this->rate_max !== null && BigDecimal::of($this->rate_max)->isLessThan($recommended)) {
            $errors['rate_max'] = ['The maximum rate must not be less than the recommended rate.'];
        }
        foreach (['spacing_row_cm', 'spacing_plant_cm'] as $field) {
            if ($this->{$field} !== null && (BigDecimal::of($this->{$field})->isLessThanOrEqualTo(0) || BigDecimal::of($this->{$field})->isGreaterThan('99999999.99'))) {
                $errors[$field] = ['Spacing must be positive and fit the supported decimal range.'];
            }
        }
        foreach (['material_type' => Seed::MATERIAL_TYPES, 'rate_basis' => self::RATE_BASES, 'rate_unit_code' => Seed::UNIT_CODES,
            'area_unit_code' => self::AREA_UNIT_CODES, 'sowing_method' => self::SOWING_METHODS, 'irrigation_condition' => self::IRRIGATION_CONDITIONS] as $field => $allowed) {
            if ($this->{$field} === null && in_array($field, ['sowing_method', 'irrigation_condition'], true)) {
                continue;
            }
            if (! in_array($this->{$field}, $allowed, true)) {
                $errors[$field] = ['Select a supported '.$field.'.'];
            }
        }
        if (! in_array($this->rate_unit_code, (new Seed(['material_type' => $this->material_type]))->compatibleUnits(), true)) {
            $errors['rate_unit_code'] = ['The rate unit is incompatible with the selected planting material.'];
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
        if ($this->seed_id !== null) {
            $seed = Seed::withTrashed()->whereKey($this->seed_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
            if ($seed === null || $seed->crop_id !== $this->crop_id) {
                $errors['seed_id'] = ['The seed product must belong to the selected crop.'];
            } elseif ((! $this->exists || $this->isDirty('seed_id') || $this->is_active)
                && ! Seed::query()->published()->whereKey($this->seed_id)->exists()) {
                $errors['seed_id'] = ['Select an available completed and published seed product.'];
            }
            if ($seed !== null && $this->crop_variety_id !== null && $seed->crop_variety_id !== null && $seed->crop_variety_id !== $this->crop_variety_id) {
                $errors['seed_id'] = ['The seed product is incompatible with the selected crop variety.'];
            }
            if ($seed !== null && $seed->material_type !== $this->material_type) {
                $errors['material_type'] = ['The material type must match the selected seed product.'];
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
     * Exact nullable conditions define equal applicability. Broader defaults remain valid fallbacks.
     * Unit changes cannot bypass duplicates: rates in kg/g or ha/acre still compete.
     * Call only after acquiring the parent crop lock.
     *
     * @param  list<int>  $regionIds
     */
    public function overlappingActiveRate(array $regionIds): ?self
    {
        $query = self::query()->where('crop_id', $this->crop_id)->where('is_active', true)
            ->where('region_scope', $this->region_scope)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id));
        foreach (['seed_id', 'season_id', 'material_type', 'sowing_method', 'irrigation_condition', 'rate_basis'] as $field) {
            $query->where($field, $this->{$field});
        }
        $seedVariety = $this->seed_id === null ? null : Seed::withTrashed()->whereKey($this->seed_id)->value('crop_variety_id');
        if ($seedVariety !== null) {
            $query->where(fn (Builder $query): Builder => $query->whereNull('crop_variety_id')->orWhere('crop_variety_id', $seedVariety));
        } else {
            $query->where('crop_variety_id', $this->crop_variety_id);
        }
        if ($this->region_scope === 'specific') {
            $query->whereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds));
        }

        return $query->orderBy('id')->first(['id']);
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'crop_seed_rate_regions') {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'crop_seed_rates' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

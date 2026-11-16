<?php

namespace App\Models;

use App\Helpers\AreaHelper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\FarmCropCycleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FarmCropCycle extends Model
{
    /** @use HasFactory<FarmCropCycleFactory> */
    use HasFactory;

    public const STEPS = ['crop_selection', 'boundary', 'seed_sowing', 'soil_assessment', 'fertilizer_planning'];

    public const SELECTION_FIELDS = ['farm_id', 'crop_id', 'crop_variety_id', 'season_id', 'sowing_method'];

    public const SEED_FIELDS = ['seed_id', 'seed_lot_id', 'seed_source', 'seed_quantity', 'seed_quantity_unit', 'seed_notes', 'sowing_date',
        'expected_harvest_date', 'expected_harvest_date_min', 'expected_harvest_date_max'];

    public const BOUNDARY_FIELDS = ['boundary_source', 'cultivated_area', 'area_unit', 'latitude', 'longitude', 'gps_accuracy_m'];

    protected $fillable = [...self::SELECTION_FIELDS, ...self::SEED_FIELDS, ...self::BOUNDARY_FIELDS];

    protected $hidden = ['boundary', 'creation_key', 'creation_payload_hash', 'created_by'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['farm_id' => 'integer', 'crop_id' => 'integer', 'crop_variety_id' => 'integer', 'season_id' => 'integer',
            'seed_id' => 'integer', 'seed_lot_id' => 'integer', 'revision' => 'integer', 'completed_step' => 'integer',
            'seed_quantity' => 'decimal:4', 'cultivated_area' => 'decimal:8', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7',
            'gps_accuracy_m' => 'decimal:2', 'wizard_progress' => 'array', 'planning_snapshot' => 'array', 'boundary_geojson' => 'array',
            'sowing_date' => 'immutable_date:Y-m-d', 'expected_harvest_date' => 'immutable_date:Y-m-d',
            'expected_harvest_date_min' => 'immutable_date:Y-m-d', 'expected_harvest_date_max' => 'immutable_date:Y-m-d',
            'finalized_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
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

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class)->withTrashed();
    }

    public function seedLot(): BelongsTo
    {
        return $this->belongsTo(SeedLot::class);
    }

    public function soilAssessments(): HasMany
    {
        return $this->hasMany(FarmCropSoilAssessment::class);
    }

    public function soilTests(): HasMany
    {
        return $this->hasMany(SoilTest::class);
    }

    public function fertilizerPlans(): HasMany
    {
        return $this->hasMany(FarmCropFertilizerPlan::class);
    }

    public function yieldEstimates(): HasMany
    {
        return $this->hasMany(FarmCropYieldEstimate::class);
    }

    public function editDraft(): HasOne
    {
        return $this->hasOne(FarmCropEditDraft::class);
    }

    public function scopeOwnedBy(Builder $query, int $userId): void
    {
        $query->whereHas('farm', fn (Builder $farm): Builder => $farm->where('user_id', $userId));
    }

    public function scopeWithBoundary(Builder $query): void
    {
        $query->addSelect('farm_crop_cycles.*')->selectRaw(DB::getDriverName() === 'sqlite'
            ? 'farm_crop_cycles.boundary AS boundary_geojson' : 'ST_AsGeoJSON(farm_crop_cycles.boundary, 9, 0) AS boundary_geojson');
    }

    public function nextStep(): ?int
    {
        foreach (self::STEPS as $index => $step) {
            if (! in_array($this->wizard_progress[$step] ?? 'pending', ['completed', 'skipped'], true)) {
                return $index + 1;
            }
        }

        return $this->status === 'draft' || ($this->relationLoaded('editDraft') && $this->editDraft !== null) ? 6 : null;
    }

    public function squareMetres(): ?string
    {
        if (isset($this->planning_snapshot['boundary']['square_metres'])) {
            return $this->planning_snapshot['boundary']['square_metres'];
        }

        return $this->boundary_geojson !== null ? Farm::boundaryMetrics($this->boundary_geojson)['square_metres']
            : AreaHelper::toSquareMeters($this->cultivated_area, $this->area_unit);
    }

    /** @param array<string, mixed> $boundary
     * @return array{type: string, coordinates: array}
     */
    public static function normalizeBoundary(array $boundary): array
    {
        if (array_diff(array_keys($boundary), ['type', 'coordinates']) !== [] || ($boundary['type'] ?? null) !== 'MultiPolygon'
            || ! is_array($boundary['coordinates'] ?? null) || ! array_is_list($boundary['coordinates']) || $boundary['coordinates'] === []
            || strlen(json_encode($boundary, JSON_THROW_ON_ERROR)) > config('farms.max_boundary_bytes')) {
            throw ValidationException::withMessages(['boundary' => ['Provide a supported GeoJSON MultiPolygon.']]);
        }
        $polygons = [];
        $points = 0;
        foreach ($boundary['coordinates'] as $polygon) {
            if (! is_array($polygon) || ! array_is_list($polygon) || $polygon === []) {
                throw ValidationException::withMessages(['boundary' => ['Every polygon needs an exterior ring.']]);
            }
            $rings = [];
            foreach ($polygon as $index => $ring) {
                $normalized = Farm::normalizeBoundary(['type' => 'MultiPolygon', 'coordinates' => [[$ring]]]);
                $rings[] = $index === 0 ? $normalized['coordinates'][0][0] : array_reverse($normalized['coordinates'][0][0]);
                $points += count($normalized['coordinates'][0][0]);
            }
            $polygons[] = $rings;
        }
        if ($points > config('farms.max_boundary_points')) {
            throw ValidationException::withMessages(['boundary' => ['The crop boundary exceeds the configured point limit.']]);
        }

        return ['type' => 'MultiPolygon', 'coordinates' => $polygons];
    }

    public static function geometryExpression(): string
    {
        return match (true) {
            DB::getDriverName() === 'sqlite' => '?',
            DB::connection()->isMaria() => 'ST_GeomFromWKB(ST_AsWKB(ST_GeomFromGeoJSON(?)), 4326)',
            default => 'ST_GeomFromGeoJSON(?, 1, 4326)',
        };
    }

    /** Call only inside the parent farm transaction. */
    public function persistBoundary(?array $boundary): void
    {
        DB::update('UPDATE farm_crop_cycles SET boundary = '.self::geometryExpression().' WHERE id = ?', [
            $boundary === null ? null : json_encode($boundary, JSON_THROW_ON_ERROR), $this->id,
        ]);
        $this->setAttribute('boundary_geojson', $boundary);
        $this->syncOriginalAttribute('boundary_geojson');
    }

    /** @return array<string, mixed> */
    public function recommendationConditions(): array
    {
        return ['crop_id' => $this->crop_id, 'crop_variety_id' => $this->crop_variety_id, 'season_id' => $this->season_id,
            'sowing_method' => $this->sowing_method,
            'irrigation_condition' => in_array($this->farm->land_type, CropSeedRate::IRRIGATION_CONDITIONS, true) ? $this->farm->land_type : null,
            'soil_condition' => match ($this->farm->soil_type) {
                'sandy', 'loamy' => $this->farm->soil_type, 'clay' => 'clayey', default => null
            }];
    }

    /** A read-only view of pending active-crop edits; live rows are untouched until finalization. */
    public function workingCopy(): self
    {
        $this->loadMissing('editDraft');
        if ($this->status !== 'active') {
            return clone $this;
        }
        if ($this->editDraft === null) {
            return clone $this;
        }
        $copy = clone $this;
        $data = $this->editDraft->draft_data;
        $copy->fill($data['attributes']);
        $copy->forceFill(['planning_snapshot' => $data['planning_snapshot'], 'wizard_progress' => $this->editDraft->wizard_progress,
            'revision' => $this->editDraft->revision, 'boundary_geojson' => $data['boundary'], 'completed_step' => $data['completed_step']]);
        $copy->unsetRelation('farm');
        $copy->setRelation('farm', Farm::query()->forApi(true)->when(DB::transactionLevel() > 0, fn (Builder $query): Builder => $query->lockForUpdate())->findOrFail($copy->farm_id));

        return $copy;
    }

    /** @return array{farm: Farm, cycle: ?self, region_context: array, conditions: array} */
    public static function catalogueContext(int $userId, ?int $farmId, ?int $cycleId, ?int $regionId = null): array
    {
        $cycle = $cycleId === null ? null : self::query()->ownedBy($userId)->withBoundary()->findOrFail($cycleId)->workingCopy();
        $farm = Farm::query()->where('user_id', $userId)->forApi(true)->findOrFail($cycle?->farm_id ?? $farmId);
        $context = $farm->regionContext();
        if ($regionId !== null && ! in_array($regionId, $context['region_ids'], true)) {
            throw ValidationException::withMessages(['region_id' => ['The region must belong to the selected farm hierarchy.']]);
        }
        if ($cycle !== null) {
            $cycle->setRelation('farm', $farm);
        }

        return ['farm' => $farm, 'cycle' => $cycle, 'region_context' => $context, 'conditions' => $cycle?->recommendationConditions() ?? [
            'irrigation_condition' => in_array($farm->land_type, CropSeedRate::IRRIGATION_CONDITIONS, true) ? $farm->land_type : null,
            'soil_condition' => match ($farm->soil_type) {
                'sandy', 'loamy' => $farm->soil_type, 'clay' => 'clayey', default => null
            },
        ]];
    }

    public static function convertQuantity(string $quantity, string $from, string $to): ?string
    {
        $units = config('farm_crops.quantity_units');
        if (! isset($units[$from], $units[$to]) || $units[$from]['dimension'] !== $units[$to]['dimension']) {
            return null;
        }

        return (string) BigDecimal::of($quantity)->multipliedBy($units[$from]['factor'])
            ->dividedBy($units[$to]['factor'], 12, RoundingMode::HALF_UP)->stripTrailingZeros();
    }
}

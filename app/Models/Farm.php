<?php

namespace App\Models;

use App\Helpers\AreaHelper;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Factories\FarmFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Farm extends Model
{
    /** @use HasFactory<FarmFactory> */
    use HasFactory;

    public const OWNERSHIP_TYPES = ['owned', 'leased', 'shared', 'other'];

    public const BOUNDARY_SOURCES = ['manual', 'gps'];

    public const BASIC_FIELDS = ['name', 'description', 'ownership_type', 'area', 'area_unit',
        'state_id', 'district_id', 'tehsil_id', 'village', 'address', 'latitude', 'longitude'];

    public const DETAIL_FIELDS = ['khasra_number', 'khata_number', 'patwari_halka_number',
        'soil_type', 'land_type', 'farmland_type', 'water_availability', 'irrigation_source', 'notes'];

    protected $fillable = [...self::BASIC_FIELDS, ...self::DETAIL_FIELDS];

    protected $attributes = ['area_source' => 'government_record', 'is_active' => true];

    protected $hidden = ['boundary', 'creation_key', 'creation_payload_hash', 'creation_expires_at'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'region_id' => 'integer', 'state_id' => 'integer',
            'district_id' => 'integer', 'tehsil_id' => 'integer', 'is_active' => 'boolean',
            'area' => 'decimal:3', 'actual_area' => 'decimal:8',
            'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'gps_accuracy_m' => 'decimal:2',
            'boundary_geojson' => 'array', 'has_boundary' => 'boolean', 'creation_expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cropCycles(): HasMany
    {
        return $this->hasMany(FarmCropCycle::class);
    }

    public function activeCrops(): HasMany
    {
        return $this->hasMany(FarmCropCycle::class)->where('status', 'active');
    }

    /** Call under the farm lock when its region or agricultural classification changes. */
    public function invalidateCropDrafts(): void
    {
        foreach ($this->cropCycles()->where('status', 'draft')->orderBy('id')->lockForUpdate()->get() as $cycle) {
            $progress = $cycle->wizard_progress;
            foreach (['seed_sowing', 'fertilizer_planning'] as $step) {
                if (($progress[$step] ?? 'pending') !== 'pending') {
                    $progress[$step] = 'needs_review';
                }
            }
            $cycle->forceFill(['wizard_progress' => $progress, 'revision' => $cycle->revision + 1])->save();
        }
    }

    /** Call before replacing or clearing geometry while holding this farm's row lock. */
    public function validateActiveCropBoundary(?array $boundary): void
    {
        $active = $this->cropCycles()->where('status', 'active')->whereNotNull('boundary')->orderBy('id')->get(['id']);
        if ($active->isEmpty()) {
            return;
        }
        if ($boundary === null) {
            throw ValidationException::withMessages(['boundary' => ['A farm boundary containing active crops cannot be removed.']]);
        }
        if (DB::getDriverName() === 'sqlite') {
            throw ValidationException::withMessages(['boundary' => ['Validate active crop containment on MySQL before replacing the farm boundary.']]);
        }
        $json = json_encode($boundary, JSON_THROW_ON_ERROR);
        if ($this->cropCycles()->where('status', 'active')->whereNotNull('boundary')
            ->whereRaw('NOT ST_Contains('.FarmCropCycle::geometryExpression().', boundary)', [$json])->exists()) {
            throw ValidationException::withMessages(['boundary' => ['The farm boundary must contain every active crop boundary.']]);
        }
    }

    /** @return array{farm_id: int, region_id: ?int, applicable_regions: array, region_ids: list<int>, regional_context_available: bool, issue: ?string} */
    public function regionContext(): array
    {
        $context = ['farm_id' => $this->id, 'region_id' => null, 'applicable_regions' => [], 'region_ids' => [],
            'regional_context_available' => false, 'issue' => null];
        $id = $this->tehsil_id ?? $this->district_id ?? $this->state_id ?? $this->region_id;
        $visited = [];
        $child = null;
        while ($id !== null) {
            if (isset($visited[$id]) || count($visited) >= count(Region::PARENT_TYPES)) {
                $context['issue'] = 'invalid_region_hierarchy';

                return $context;
            }
            $region = Region::query()->withLocalizedName()->find($id);
            if ($region === null || ! $region->is_active || ! array_key_exists($region->region_type, Region::PARENT_TYPES)
                || ($child !== null && Region::PARENT_TYPES[$child] !== $region->region_type)) {
                $context['issue'] = 'invalid_region_hierarchy';

                return $context;
            }
            $visited[$id] = ['id' => $region->id, 'name' => $region->localized_name, 'region_type' => $region->region_type, 'parent_id' => $region->parent_id];
            $child = $region->region_type;
            $id = $region->parent_id;
        }
        if ($child !== null && Region::PARENT_TYPES[$child] !== null) {
            $context['issue'] = 'missing_region_parent';

            return $context;
        }
        foreach (['region_id' => null, 'state_id' => 'state', 'district_id' => 'district', 'tehsil_id' => 'subdistrict'] as $field => $type) {
            if ($this->{$field} !== null && (! isset($visited[$this->{$field}]) || ($type !== null && $visited[$this->{$field}]['region_type'] !== $type))) {
                $context['issue'] = 'inconsistent_farm_regions';

                return $context;
            }
        }
        $context['region_ids'] = array_keys($visited);
        $context['region_id'] = $context['region_ids'][0] ?? null;
        $context['applicable_regions'] = array_values($visited);
        $context['regional_context_available'] = $visited !== [];

        return $context;
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'state_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'district_id');
    }

    public function tehsil(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'tehsil_id');
    }

    public function scopeForApi(Builder $query, bool $includeBoundary = false): void
    {
        $columns = ['id', 'user_id', ...self::BASIC_FIELDS, ...self::DETAIL_FIELDS, 'area_source', 'region_id',
            'actual_area', 'actual_area_unit', 'boundary_source', 'gps_accuracy_m', 'is_active', 'created_at', 'updated_at'];
        $query->select(array_map(fn (string $column): string => 'farms.'.$column, $columns))
            ->selectRaw('(farms.boundary IS NOT NULL) as has_boundary')
            ->withCount('activeCrops')
            ->with(['activeCrops' => fn (HasMany $crops): HasMany => $crops
                ->select(['id', 'farm_id', 'crop_id', 'crop_variety_id', 'cultivated_area', 'area_unit', 'status'])
                ->with('crop.translations')->orderBy('id')])
            ->with([
                'state' => fn (BelongsTo $region): BelongsTo => $region->withLocalizedName(),
                'district' => fn (BelongsTo $region): BelongsTo => $region->withLocalizedName(),
                'tehsil' => fn (BelongsTo $region): BelongsTo => $region->withLocalizedName(),
            ]);
        if ($includeBoundary) {
            $query->selectRaw(DB::getDriverName() === 'sqlite'
                ? 'farms.boundary as boundary_geojson'
                : 'ST_AsGeoJSON(farms.boundary, 9, 0) as boundary_geojson');
        }
    }

    /** @return array<string, list<string>> */
    public function locationErrors(bool $lock = false): array
    {
        $ids = array_filter([$this->state_id, $this->district_id, $this->tehsil_id], fn (?int $id): bool => $id !== null);
        $regions = Region::query()->whereIn('id', $ids)->orderBy('id')
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'parent_id', 'region_type', 'is_active'])->keyBy('id');
        $errors = [];
        foreach (['state_id' => 'state', 'district_id' => 'district', 'tehsil_id' => 'subdistrict'] as $field => $type) {
            if ($this->{$field} === null) {
                continue;
            }
            $label = $field === 'tehsil_id' ? 'tehsil' : $type;
            $region = $regions->get($this->{$field});
            if ($region === null || $region->region_type !== $type) {
                $errors[$field] = ['Select an existing '.$label.'.'];
            } elseif (! $region->is_active && (! $this->exists || $this->isDirty($field))) {
                $errors[$field] = ['Inactive '.$label.'s cannot be newly selected.'];
            }
        }
        foreach (['district_id' => 'state_id', 'tehsil_id' => 'district_id'] as $child => $parent) {
            if ($this->{$child} !== null && ! isset($errors[$child]) && $regions->get($this->{$child})->parent_id !== $this->{$parent}) {
                $errors[$child] = [$child === 'district_id'
                    ? 'The district must belong to the selected state.'
                    : 'The tehsil must belong to the selected district.'];
            }
        }
        if (($this->latitude === null) !== ($this->longitude === null)) {
            $errors['latitude'] = ['Latitude and longitude must both be supplied or both be null.'];
            $errors['longitude'] = $errors['latitude'];
        }
        if ($this->exists && $this->getRawOriginal('boundary') !== null && $this->isDirty(['latitude', 'longitude'])) {
            $errors['latitude'] = ['Coordinates are derived from the farm boundary. Update the boundary instead.'];
            $errors['longitude'] = $errors['latitude'];
        }

        return $errors;
    }

    protected function areaSqm(): Attribute
    {
        return Attribute::make(get: fn (): ?string => AreaHelper::toSquareMeters($this->area, $this->area_unit));
    }

    protected function actualAreaSqm(): Attribute
    {
        return Attribute::make(get: fn (): ?string => AreaHelper::toSquareMeters($this->actual_area, $this->actual_area_unit));
    }

    /** @return array{actual_area: string, actual_area_unit: string} */
    public function calculatedAreaAttributes(string $squareMetres): array
    {
        $unit = AreaHelper::canonicalUnit($this->area_unit) ?? config('area_units.default_unit');
        $converted = AreaHelper::fromSquareMeters($squareMetres, $unit);
        $converted = $converted === null ? null : BigDecimal::of($converted)->toScale(8, RoundingMode::HALF_UP);
        if ($converted === null || $converted->isLessThanOrEqualTo(0) || $converted->isGreaterThan('9999999999.99999999')) {
            throw ValidationException::withMessages(['boundary' => ['The calculated farm area is outside the supported precision.']]);
        }

        return ['actual_area' => (string) $converted, 'actual_area_unit' => $unit];
    }

    /**
     * Accept one simple exterior ring. Holes, additional parcels and alternative CRSs are outside this wizard.
     *
     * @param  array<string, mixed>  $boundary
     * @return array{type: string, coordinates: array}
     */
    public static function normalizeBoundary(array $boundary): array
    {
        $fail = static function (string $message): never {
            throw ValidationException::withMessages(['boundary' => [$message]]);
        };
        if (strlen(json_encode($boundary, JSON_THROW_ON_ERROR)) > config('farms.max_boundary_bytes')) {
            $fail('The farm boundary exceeds the supported size limit.');
        }
        if (array_diff(array_keys($boundary), ['type', 'coordinates']) !== [] || ($boundary['type'] ?? null) !== 'MultiPolygon') {
            $fail('Provide a GeoJSON MultiPolygon using longitude, latitude coordinates and no alternative CRS.');
        }
        $polygons = $boundary['coordinates'] ?? null;
        if (! is_array($polygons) || ! array_is_list($polygons) || count($polygons) !== 1
            || ! is_array($polygons[0]) || ! array_is_list($polygons[0]) || count($polygons[0]) !== 1) {
            $fail('Provide exactly one polygon with one exterior ring.');
        }
        $ring = $polygons[0][0];
        if (! is_array($ring) || ! array_is_list($ring) || count($ring) < 4 || count($ring) > config('farms.max_boundary_points')) {
            $fail('Provide a closed boundary with at least three vertices within the configured point limit.');
        }
        foreach ($ring as &$point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2) {
                $fail('Each boundary point must contain exactly longitude and latitude.');
            }
            foreach ($point as $coordinate) {
                if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate)) {
                    $fail('Boundary coordinates must be finite JSON numbers.');
                }
            }
            if ($point[0] < -180 || $point[0] > 180 || $point[1] < -90 || $point[1] > 90) {
                $fail('Boundary longitude must be between -180 and 180 and latitude between -90 and 90.');
            }
            $point = [(float) $point[0], (float) $point[1]];
            if ($point[0] === -180.0) {
                $point[0] = 180.0;
            }
        }
        unset($point);
        if ($ring[0] !== $ring[count($ring) - 1]) {
            $fail('The farm boundary ring must be closed.');
        }
        $vertices = array_slice($ring, 0, -1);
        if (count(array_unique(array_map(fn (array $point): string => json_encode($point, JSON_THROW_ON_ERROR), $vertices))) !== count($vertices)) {
            $fail('Use at least three distinct boundary vertices without repeated intermediate points.');
        }
        $size = count($vertices);
        $signedArea = 0.0;
        $origin = $ring[0];
        for ($i = 0; $i < $size; $i++) {
            if (abs($ring[$i + 1][0] - $ring[$i][0]) > 180) {
                $fail('Farm boundaries crossing the antimeridian are not supported.');
            }
            $signedArea += ($ring[$i][0] - $origin[0]) * ($ring[$i + 1][1] - $origin[1])
                - ($ring[$i + 1][0] - $origin[0]) * ($ring[$i][1] - $origin[1]);
            for ($j = $i + 1; $j < $size; $j++) {
                if ($j === $i + 1 || ($i === 0 && $j === $size - 1)) {
                    continue;
                }
                if (self::segmentsIntersect($ring[$i], $ring[$i + 1], $ring[$j], $ring[$j + 1])) {
                    $fail('The farm boundary must not intersect or touch itself.');
                }
            }
        }
        if (abs($signedArea) < 1.0e-16) {
            $fail('The farm boundary must enclose a positive area.');
        }
        if ($signedArea < 0) {
            $ring = array_reverse($ring);
        }

        return ['type' => 'MultiPolygon', 'coordinates' => [[$ring]]];
    }

    /** @param array{float, float} $a
     * @param  array{float, float}  $b
     * @param  array{float, float}  $c
     * @param  array{float, float}  $d
     */
    private static function segmentsIntersect(array $a, array $b, array $c, array $d): bool
    {
        if (max($a[0], $b[0]) < min($c[0], $d[0]) || max($c[0], $d[0]) < min($a[0], $b[0])
            || max($a[1], $b[1]) < min($c[1], $d[1]) || max($c[1], $d[1]) < min($a[1], $b[1])) {
            return false;
        }
        $cross = static fn (array $p, array $q, array $r): float => ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
        $abC = $cross($a, $b, $c);
        $abD = $cross($a, $b, $d);
        $cdA = $cross($c, $d, $a);
        $cdB = $cross($c, $d, $b);

        return $abC * $abD <= 0 && $cdA * $cdB <= 0;
    }

    /**
     * MySQL uses WGS84 geodetic area. MariaDB/SQLite use the Chamberlain-Duquette spherical approximation.
     * The compatibility calculation never treats longitude/latitude degrees as square metres.
     *
     * @param  array{type: string, coordinates: array}  $boundary
     * @return array{square_metres: string, latitude: float, longitude: float}
     */
    public static function boundaryMetrics(array $boundary): array
    {
        $vertices = [];
        foreach ($boundary['coordinates'] as $polygon) {
            $vertices = [...$vertices, ...array_slice($polygon[0], 0, -1)];
        }
        if (DB::getDriverName() !== 'sqlite' && ! DB::connection()->isMaria()) {
            $json = json_encode($boundary, JSON_THROW_ON_ERROR);
            $valid = DB::selectOne('SELECT ST_IsValid(ST_GeomFromGeoJSON(?, 1, 4326)) AS valid', [$json]);
            if (! $valid->valid) {
                throw ValidationException::withMessages(['boundary' => ['The farm boundary is geometrically invalid.']]);
            }
            $area = (float) DB::selectOne('SELECT ST_Area(ST_GeomFromGeoJSON(?, 1, 4326)) AS area', [$json])->area;
        } else {
            /** Chamberlain and Duquette, JPL Publication 07-03, section on spherical polygon area. */
            $area = 0.0;
            foreach ($boundary['coordinates'] as $polygon) {
                foreach ($polygon as $index => $ring) {
                    $sum = 0.0;
                    for ($i = 0, $count = count($ring) - 1; $i < $count; $i++) {
                        $next = $ring[$i + 1];
                        $sum += deg2rad($next[0] - $ring[$i][0]) * (sin(deg2rad($ring[$i][1])) + sin(deg2rad($next[1])));
                    }
                    $area += ($index === 0 ? 1 : -1) * abs($sum) * 6371008.8 ** 2 / 2;
                }
            }
        }
        if (! is_finite($area) || $area <= 0 || $area > 999999999999.999999) {
            throw ValidationException::withMessages(['boundary' => ['The farm boundary must enclose a supported positive area.']]);
        }

        return ['square_metres' => number_format($area, 6, '.', ''),
            'latitude' => array_sum(array_column($vertices, 1)) / count($vertices),
            'longitude' => array_sum(array_column($vertices, 0)) / count($vertices)];
    }

    /**
     * Call inside the owning farm transaction; geometry and derived fields are written in one bound statement.
     *
     * @param  array{type: string, coordinates: array}|null  $boundary
     * @param  array{square_metres: string, latitude: float, longitude: float}|null  $metrics
     */
    public function persistBoundary(?array $boundary, ?array $metrics, ?string $source, mixed $accuracy): void
    {
        $values = $boundary === null
            ? ['actual_area' => null, 'actual_area_unit' => null]
            : $this->calculatedAreaAttributes($metrics['square_metres']);
        $json = $boundary === null ? null : json_encode($boundary, JSON_THROW_ON_ERROR);
        $expression = match (true) {
            DB::getDriverName() === 'sqlite' => '?',
            DB::connection()->isMaria() => 'ST_GeomFromWKB(ST_AsWKB(ST_GeomFromGeoJSON(?)), 4326)',
            default => 'ST_GeomFromGeoJSON(?, 1, 4326)',
        };
        DB::update('UPDATE farms SET boundary = '.$expression.', boundary_source = ?, gps_accuracy_m = ?, actual_area = ?, actual_area_unit = ?, latitude = ?, longitude = ?, updated_at = ? WHERE id = ? AND user_id = ?', [
            $json, $boundary === null ? null : $source, $boundary !== null && $source === 'gps' ? $accuracy : null,
            $values['actual_area'], $values['actual_area_unit'],
            $metrics['latitude'] ?? $this->latitude, $metrics['longitude'] ?? $this->longitude,
            $this->freshTimestampString(), $this->id, $this->user_id,
        ]);
    }
}

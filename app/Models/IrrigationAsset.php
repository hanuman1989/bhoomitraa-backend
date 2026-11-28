<?php

namespace App\Models;

use App\Enums\IrrigationAssetStatus;
use App\Enums\IrrigationAssetType;
use App\Enums\IrrigationOwnershipType;
use App\Enums\IrrigationPipelineType;
use App\Enums\IrrigationSourceType;
use App\Enums\IrrigationWaterAvailability;
use Database\Factories\IrrigationAssetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * A farmer's water source or pipeline. Assets are independent of farms. The raw
 * geometry column is never selected: read it with withGeoJson() and write it with
 * persistGeometry(), both as GeoJSON [lng, lat] in SRID 4326.
 */
class IrrigationAsset extends Model
{
    /** @use HasFactory<IrrigationAssetFactory> */
    use HasFactory, SoftDeletes;

    /** Columns read for the API; the geometry is added as GeoJSON by withGeoJson(). */
    public const API_COLUMNS = ['id', 'user_id', 'name', 'asset_type', 'source_type', 'pipeline_type', 'length_meters',
        'diameter_mm', 'depth_meters', 'water_availability', 'ownership_type', 'status', 'notes', 'created_at', 'updated_at', 'deleted_at'];

    /** user_id, asset_type, geometry and length_meters are set explicitly by IrrigationAssetService. */
    protected $fillable = ['name', 'source_type', 'pipeline_type', 'diameter_mm', 'depth_meters',
        'water_availability', 'ownership_type', 'status', 'notes'];

    protected $hidden = ['user_id', 'geometry', 'deleted_at'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'asset_type' => IrrigationAssetType::class,
            'source_type' => IrrigationSourceType::class,
            'pipeline_type' => IrrigationPipelineType::class,
            'water_availability' => IrrigationWaterAvailability::class,
            'ownership_type' => IrrigationOwnershipType::class,
            'status' => IrrigationAssetStatus::class,
            'length_meters' => 'decimal:2',
            'diameter_mm' => 'decimal:2',
            'depth_meters' => 'decimal:2',
            'geometry_geojson' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Selects the API columns and the geometry as GeoJSON (SQLite test databases store the GeoJSON text). */
    public function scopeWithGeoJson(Builder $query): void
    {
        $query->select(array_map(fn (string $column): string => 'irrigation_assets.'.$column, self::API_COLUMNS))
            ->selectRaw(DB::getDriverName() === 'sqlite'
                ? 'irrigation_assets.`geometry` AS geometry_geojson'
                : 'ST_AsGeoJSON(irrigation_assets.`geometry`) AS geometry_geojson');
    }

    /**
     * Applies validated list and map filters. Search matches the name, or source and
     * pipeline types whose English or Hindi label contains the text.
     *
     * @param  array{asset_type?: string|null, source_type?: string|null, pipeline_type?: string|null, status?: string|null, search?: string|null}  $filters
     */
    public function scopeApplyFilters(Builder $query, array $filters): void
    {
        foreach (['asset_type', 'source_type', 'pipeline_type', 'status'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where('irrigation_assets.'.$field, $filters[$field]);
            }
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $sourceTypes = self::typesLabelled(IrrigationSourceType::cases(), $search);
        $pipelineTypes = self::typesLabelled(IrrigationPipelineType::cases(), $search);
        $query->where(function (Builder $query) use ($pattern, $sourceTypes, $pipelineTypes): void {
            $query->whereRaw("irrigation_assets.name LIKE ? ESCAPE '!'", [$pattern]);
            if ($sourceTypes !== []) {
                $query->orWhereIn('irrigation_assets.source_type', $sourceTypes);
            }
            if ($pipelineTypes !== []) {
                $query->orWhereIn('irrigation_assets.pipeline_type', $pipelineTypes);
            }
        });
    }

    /**
     * Writes the geometry in one bound statement; null removes it. Call inside the
     * asset's write transaction, with a geometry IrrigationGeometryService accepted.
     *
     * @param  array{type: string, coordinates: array<mixed>}|null  $geometry
     */
    public function persistGeometry(?array $geometry): void
    {
        $value = match (true) {
            $geometry === null => 'NULL',
            DB::getDriverName() === 'sqlite' => '?',
            default => 'ST_GeomFromGeoJSON(?, 1, 4326)',
        };
        $bindings = $geometry === null ? [] : [json_encode($geometry, JSON_THROW_ON_ERROR)];
        DB::update('UPDATE irrigation_assets SET `geometry` = '.$value.', updated_at = ? WHERE id = ? AND user_id = ?',
            [...$bindings, $this->freshTimestampString(), $this->getKey(), $this->user_id]);
    }

    /**
     * Keys of the types whose English or Hindi label contains the search text, so
     * label search never builds SQL from user text.
     *
     * @param  list<IrrigationSourceType|IrrigationPipelineType>  $types
     * @return list<string>
     */
    private static function typesLabelled(array $types, string $search): array
    {
        $matching = array_filter($types, fn (IrrigationSourceType|IrrigationPipelineType $type): bool => mb_stripos($type->label('en'), $search) !== false
            || mb_stripos($type->label('hi'), $search) !== false);

        return array_values(array_map(fn (IrrigationSourceType|IrrigationPipelineType $type): string => $type->value, $matching));
    }
}

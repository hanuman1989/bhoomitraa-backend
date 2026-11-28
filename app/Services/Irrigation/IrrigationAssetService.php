<?php

namespace App\Services\Irrigation;

use App\Enums\IrrigationAssetStatus;
use App\Enums\IrrigationAssetType;
use App\Enums\IrrigationSourceType;
use App\Models\IrrigationAsset;
use App\Models\User;
use BackedEnum;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and updates a farmer's irrigation assets. The server is the authority: it
 * sets the owner and category, validates the whole effective record and calculates
 * the length of mapped pipelines. Every query goes through the farmer's relation.
 */
class IrrigationAssetService
{
    public function __construct(private readonly IrrigationGeometryService $geometry) {}

    /**
     * @param  array<string, mixed>  $input  validated request data
     *
     * @throws ValidationException
     */
    public function create(User $user, array $input): IrrigationAsset
    {
        return DB::transaction(function () use ($user, $input): IrrigationAsset {
            $asset = new IrrigationAsset;
            $asset->user_id = $user->getKey();
            $asset->asset_type = IrrigationAssetType::from($input['asset_type']);
            $asset->status = IrrigationAssetStatus::Operational;
            $geometry = $this->apply($asset, $input, null);
            $asset->save();
            if ($geometry !== null) {
                $asset->persistGeometry($geometry);
            }

            return $user->irrigationAssets()->withGeoJson()->findOrFail($asset->getKey());
        });
    }

    /**
     * Partial update: omitted fields keep their values and an explicit null clears an
     * optional field. The locked record is validated again inside the transaction.
     *
     * @param  array<string, mixed>  $input  validated request data
     *
     * @throws ValidationException
     */
    public function update(User $user, string $id, array $input): IrrigationAsset
    {
        return DB::transaction(function () use ($user, $id, $input): IrrigationAsset {
            $asset = $user->irrigationAssets()->withGeoJson()->lockForUpdate()->findOrFail($id);
            $geometry = $this->apply($asset, $input, $asset->geometry_geojson);
            $asset->save();
            if (array_key_exists('geometry', $input)) {
                $asset->persistGeometry($geometry);
            }

            return $user->irrigationAssets()->withGeoJson()->findOrFail($asset->getKey());
        });
    }

    /**
     * Errors in the effective record: the stored values overlaid with the input.
     *
     * @param  array<string, mixed>  $input
     * @param  array{type: string, coordinates: array<mixed>}|null  $storedGeometry
     * @return array<string, list<string>>
     */
    public function errors(IrrigationAsset $asset, array $input, ?array $storedGeometry): array
    {
        $effective = function (string $field) use ($asset, $input): mixed {
            $value = array_key_exists($field, $input) ? $input[$field] : $asset->getAttribute($field);

            return $value instanceof BackedEnum ? $value->value : $value;
        };
        $errors = [];
        $isSource = $asset->asset_type === IrrigationAssetType::Source;
        if ($effective($isSource ? 'source_type' : 'pipeline_type') === null) {
            $errors[$isSource ? 'source_type' : 'pipeline_type'][] = __($isSource
                ? 'irrigation.validation.source_type_required' : 'irrigation.validation.pipeline_type_required');
        }
        $otherCategoryFields = $isSource
            ? ['pipeline_type', 'length_meters', 'diameter_mm']
            : ['source_type', 'depth_meters', 'water_availability'];
        foreach ($otherCategoryFields as $field) {
            if ($effective($field) !== null) {
                $errors[$field][] = __($isSource ? 'irrigation.validation.pipeline_only' : 'irrigation.validation.source_only');
            }
        }
        $sourceType = $isSource ? IrrigationSourceType::tryFrom((string) $effective('source_type')) : null;
        if ($sourceType !== null && ! $sourceType->supportsDepth() && $effective('depth_meters') !== null) {
            $errors['depth_meters'][] = __('irrigation.validation.depth_not_supported');
        }
        if (($input['geometry'] ?? null) !== null) {
            $key = $this->geometry->error($input['geometry'], $asset->asset_type);
            if ($key !== null) {
                $errors['geometry'][] = __($key, ['max' => $this->geometry->maxRoutePoints()]);
            }
        }
        $geometry = array_key_exists('geometry', $input) ? $input['geometry'] : $storedGeometry;
        if (! $isSource && $geometry !== null && ($input['length_meters'] ?? null) !== null) {
            $errors['length_meters'][] = __('irrigation.validation.length_with_route');
        }

        return $errors;
    }

    /**
     * Totals over every non-deleted asset of the farmer, inactive ones included, in one
     * aggregate query. List filters never apply. The total length adds known lengths
     * only: null when no pipeline has one, "0.00" when there are no pipelines. Sources
     * needing attention are those that need maintenance or are out of service.
     *
     * @return array{total_assets: int, water_sources: int, water_sources_needing_attention: int, pipelines: int,
     *     pipelines_with_route: int, total_pipeline_length_meters: string|null, pipelines_with_known_length: int,
     *     pipelines_with_unknown_length: int, mapped_assets: int, unmapped_assets: int}
     */
    public function summary(User $user): array
    {
        $totals = $user->irrigationAssets()->toBase()->selectRaw("COUNT(*) AS total_assets,
            SUM(CASE WHEN asset_type = 'source' THEN 1 ELSE 0 END) AS water_sources,
            SUM(CASE WHEN asset_type = 'source' AND status IN ('needs_maintenance', 'out_of_service') THEN 1 ELSE 0 END) AS attention,
            SUM(CASE WHEN asset_type = 'pipeline' THEN 1 ELSE 0 END) AS pipelines,
            SUM(CASE WHEN asset_type = 'pipeline' AND `geometry` IS NOT NULL THEN 1 ELSE 0 END) AS routes,
            SUM(CASE WHEN asset_type = 'pipeline' THEN length_meters END) AS total_length,
            SUM(CASE WHEN asset_type = 'pipeline' AND length_meters IS NOT NULL THEN 1 ELSE 0 END) AS known_lengths,
            SUM(CASE WHEN `geometry` IS NOT NULL THEN 1 ELSE 0 END) AS mapped_assets")->first();
        $assets = (int) $totals->total_assets;
        $pipelines = (int) $totals->pipelines;
        $known = (int) $totals->known_lengths;
        $mapped = (int) $totals->mapped_assets;

        return [
            'total_assets' => $assets,
            'water_sources' => (int) $totals->water_sources,
            'water_sources_needing_attention' => (int) $totals->attention,
            'pipelines' => $pipelines,
            'pipelines_with_route' => (int) $totals->routes,
            'total_pipeline_length_meters' => match (true) {
                $pipelines === 0 => '0.00',
                $known === 0 => null,
                default => (string) BigDecimal::of((string) $totals->total_length)->toScale(2, RoundingMode::HALF_UP),
            },
            'pipelines_with_known_length' => $known,
            'pipelines_with_unknown_length' => $pipelines - $known,
            'mapped_assets' => $mapped,
            'unmapped_assets' => $assets - $mapped,
        ];
    }

    /**
     * Validates and fills the effective record, applying the length rules, and returns
     * the geometry to store.
     *
     * Pipeline length: a route always means the server-calculated length; without a
     * route an input length is kept; removing a route clears the old length unless a
     * new manual length arrives in the same request.
     *
     * @param  array<string, mixed>  $input
     * @param  array{type: string, coordinates: array<mixed>}|null  $storedGeometry
     * @return array{type: string, coordinates: array<mixed>}|null
     *
     * @throws ValidationException
     */
    private function apply(IrrigationAsset $asset, array $input, ?array $storedGeometry): ?array
    {
        $errors = $this->errors($asset, $input, $storedGeometry);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $asset->fill(Arr::only($input, $asset->getFillable()));
        $geometry = match (true) {
            ! array_key_exists('geometry', $input) => $storedGeometry,
            $input['geometry'] === null => null,
            default => $this->geometry->normalize($input['geometry']),
        };
        if ($asset->asset_type === IrrigationAssetType::Pipeline) {
            $asset->length_meters = match (true) {
                $geometry !== null => $this->geometry->lengthMeters($geometry['coordinates']),
                array_key_exists('length_meters', $input) => $input['length_meters'],
                $storedGeometry !== null => null,
                default => $asset->length_meters,
            };
        }

        return $geometry;
    }
}

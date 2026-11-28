<?php

namespace App\Http\Resources\Account;

use App\Enums\IrrigationAssetType;
use App\Models\IrrigationAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mapped asset as a GeoJSON Feature with minimal properties (no notes). The
 * controller wraps the features in a FeatureCollection.
 *
 * @mixin IrrigationAsset
 */
class IrrigationAssetMapResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isSource = $this->asset_type === IrrigationAssetType::Source;
        $type = $isSource ? $this->source_type : $this->pipeline_type;

        return [
            'type' => 'Feature',
            'id' => (string) $this->id,
            'geometry' => $this->geometry_geojson,
            'properties' => [
                'name' => $this->name,
                'asset_type' => $this->asset_type->value,
                ($isSource ? 'source_type' : 'pipeline_type') => $type?->value,
                'status' => $this->status->value,
                'type_label' => $type?->label(),
                'status_label' => $this->status->label(),
            ],
        ];
    }
}

<?php

namespace App\Http\Resources\Account;

use App\Enums\IrrigationAssetType;
use App\Models\IrrigationAsset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An irrigation asset loaded with IrrigationAsset::withGeoJson(). Labels use the request
 * locale; user_id and deleted_at are never exposed.
 *
 * @mixin IrrigationAsset
 */
class IrrigationAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = $this->asset_type === IrrigationAssetType::Source ? $this->source_type : $this->pipeline_type;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'asset_type' => $this->asset_type->value,
            'source_type' => $this->source_type?->value,
            'pipeline_type' => $this->pipeline_type?->value,
            'geometry' => $this->geometry_geojson,
            'length_meters' => $this->length_meters,
            'diameter_mm' => $this->diameter_mm,
            'depth_meters' => $this->depth_meters,
            'water_availability' => $this->water_availability?->value,
            'ownership_type' => $this->ownership_type?->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'type_label' => $type?->label(),
            'status_label' => $this->status->label(),
            'ownership_label' => $this->ownership_type?->label(),
            'water_availability_label' => $this->water_availability?->label(),
            'length_source' => match (true) {
                $this->length_meters === null => null,
                $this->geometry_geojson !== null => 'calculated',
                default => 'manual',
            },
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

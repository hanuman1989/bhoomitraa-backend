<?php

namespace App\Http\Resources;

use App\Models\Farm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id, 'name' => $this->name, 'ownership_type' => $this->ownership_type,
            'area' => $this->area, 'area_unit' => $this->area_unit, 'area_sqm' => $this->area_sqm, 'area_source' => $this->area_source,
            'actual_area' => $this->actual_area, 'actual_area_unit' => $this->actual_area_unit, 'actual_area_sqm' => $this->actual_area_sqm,
            'region_id' => $this->region_id, 'state_id' => $this->state_id, 'district_id' => $this->district_id, 'tehsil_id' => $this->tehsil_id,
            'state' => $this->whenLoaded('state', fn (): array => ['id' => $this->state->id, 'name' => $this->state->localized_name]),
            'district' => $this->whenLoaded('district', fn (): array => ['id' => $this->district->id, 'name' => $this->district->localized_name]),
            'tehsil' => $this->whenLoaded('tehsil', fn (): array => ['id' => $this->tehsil->id, 'name' => $this->tehsil->localized_name]),
            'village' => $this->village, 'address' => $this->address,
            'latitude' => $this->latitude, 'longitude' => $this->longitude,
            'has_boundary' => $this->has_boundary, 'boundary_source' => $this->boundary_source, 'gps_accuracy_m' => $this->gps_accuracy_m,
            'boundary' => $this->when(array_key_exists('boundary_geojson', $this->resource->getAttributes()), fn (): ?array => $this->boundary_geojson),
            'is_active' => $this->is_active,
            'active_crop' => $this->whenLoaded('activeCrops', fn (): ?array => $this->activeCrops->count() === 1
                ? ['id' => $this->activeCrops->first()->id, 'crop_id' => $this->activeCrops->first()->crop_id,
                    'crop_name' => $this->activeCrops->first()->crop?->translatedName()] : null, null),
            'active_crops_count' => $this->whenCounted('activeCrops'),
            'active_crops' => $this->whenLoaded('activeCrops', fn (): array => $this->activeCrops->map(fn ($cycle): array => [
                ...$cycle->only(['id', 'crop_id', 'crop_variety_id', 'cultivated_area', 'area_unit', 'status']),
                'crop_name' => $cycle->crop?->translatedName(), 'crop_color' => $cycle->crop?->crop_color,
            ])->all()),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
        ];
        if (! $request->routeIs('account.farms.index')) {
            $data['description'] = $this->description;
            foreach (Farm::DETAIL_FIELDS as $field) {
                $data[$field] = $this->{$field};
            }
        }

        return $data;
    }
}

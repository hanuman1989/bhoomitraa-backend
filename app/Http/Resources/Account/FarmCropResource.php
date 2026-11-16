<?php

namespace App\Http\Resources\Account;

use App\Http\Resources\FarmResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmCropResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'completed_step' => $this->completed_step,
            'wizard_progress' => $this->wizard_progress, 'next_step' => $this->resource->nextStep(), 'revision' => $this->revision,
            'farm' => $this->whenLoaded('farm', fn () => new FarmResource($this->farm)),
            'farm_location' => $this->whenLoaded('farm', fn () => $this->farm->only(['state_id', 'district_id', 'tehsil_id', 'village', 'address'])),
            'crop' => $this->whenLoaded('crop', fn () => new CropResource($this->crop)),
            'crop_variety' => $this->whenLoaded('cropVariety', fn () => $this->cropVariety === null ? null : new CropVarietyResource($this->cropVariety)),
            'season' => $this->whenLoaded('season', fn () => new SeasonResource($this->season)),
            'sowing_method' => $this->sowing_method, 'sowing_date' => $this->sowing_date?->toDateString(),
            'expected_harvest_date' => $this->expected_harvest_date?->toDateString(),
            'expected_harvest_date_min' => $this->expected_harvest_date_min?->toDateString(),
            'expected_harvest_date_max' => $this->expected_harvest_date_max?->toDateString(),
            'cultivated_area' => $this->cultivated_area, 'area_unit' => $this->area_unit,
            'calculated_area_sqm' => $this->calculated_area_sqm,
            'boundary_source' => $this->boundary_source, 'latitude' => $this->latitude, 'longitude' => $this->longitude,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            ...($this->detail_data ?? [])];
    }
}

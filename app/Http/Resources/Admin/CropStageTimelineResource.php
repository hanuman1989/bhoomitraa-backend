<?php

namespace App\Http\Resources\Admin;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropStageTimelineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'crop_id' => $this->whenLoaded('cropStage', fn (): ?int => $this->cropStage?->crop_id),
            'crop_name' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->relationLoaded('crop')
                ? $this->cropStage->crop?->translatedName($locale) : null),
            'crop_stage_id' => $this->crop_stage_id,
            'crop_stage_name' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->translatedName($locale)),
            'crop_variety_id' => $this->crop_variety_id,
            'crop_variety_name' => $this->whenLoaded('cropVariety', fn (): ?string => $this->cropVariety?->translatedName($locale)),
            'season_id' => $this->season_id,
            'season_name' => $this->whenLoaded('season', fn (): ?string => $this->season?->translatedName($locale)),
            'region_scope' => $this->when(! $request->routeIs('admin.crop-stage-timelines.index'), $this->region_scope),
            'region_ids' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): int => (int) $region->id)->all()),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): array => [
                'id' => $region->id,
                'parent_id' => $region->parent_id,
                'name' => $region->relationLoaded('translations') ? $region->translatedName($locale) : null,
                'region_type' => $region->region_type,
            ])->all()),
            'days_after_sowing_min' => $this->days_after_sowing_min,
            'days_after_sowing_max' => $this->days_after_sowing_max,
            'source_id' => $this->source_id,
            'source_title' => $this->whenLoaded('source', fn (): ?string => $this->source?->translatedTitle($locale)),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

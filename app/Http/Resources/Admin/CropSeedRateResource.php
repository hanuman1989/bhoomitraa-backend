<?php

namespace App\Http\Resources\Admin;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropSeedRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'crop_id' => $this->crop_id, 'crop_variety_id' => $this->crop_variety_id,
            'seed_id' => $this->seed_id, 'season_id' => $this->season_id,
            'material_type' => $this->material_type, 'sowing_method' => $this->sowing_method,
            'irrigation_condition' => $this->irrigation_condition, 'rate_basis' => $this->rate_basis,
            'rate_min' => $this->rate_min, 'rate_recommended' => $this->rate_recommended, 'rate_max' => $this->rate_max,
            'rate_unit_code' => $this->rate_unit_code, 'area_unit_code' => $this->area_unit_code,
            'spacing_row_cm' => $this->spacing_row_cm, 'spacing_plant_cm' => $this->spacing_plant_cm,
            'region_scope' => $this->region_scope, 'source_id' => $this->source_id, 'notes' => $this->notes, 'is_active' => $this->is_active,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName()),
            'crop_variety_name' => $this->whenLoaded('cropVariety', fn (): ?string => $this->cropVariety?->translatedName()),
            'season_name' => $this->whenLoaded('season', fn (): ?string => $this->season?->translatedName()),
            'crop' => $this->whenLoaded('crop', fn (): ?array => $this->crop === null ? null : ['id' => $this->crop->id, 'name' => $this->crop->translatedName()]),
            'crop_variety' => $this->whenLoaded('cropVariety', fn (): ?array => $this->cropVariety === null ? null : ['id' => $this->cropVariety->id, 'name' => $this->cropVariety->translatedName()]),
            'season' => $this->whenLoaded('season', fn (): ?array => $this->season === null ? null : ['id' => $this->season->id, 'name' => $this->season->translatedName()]),
            'seed' => $this->whenLoaded('seed', fn (): ?array => $this->seed === null ? null : [
                'id' => $this->seed->id, 'name' => $this->seed->translation()?->name, 'sku' => $this->seed->sku,
                'crop_id' => $this->seed->crop_id, 'crop_variety_id' => $this->seed->crop_variety_id, 'material_type' => $this->seed->material_type,
                'default_unit_code' => $this->seed->default_unit_code, 'reference_pack_quantity' => $this->seed->reference_pack_quantity,
                'reference_pack_unit_code' => $this->seed->reference_pack_unit_code,
                'primary_image' => $this->seed->primaryImage === null ? null : new SeedImageResource($this->seed->primaryImage),
            ]),
            'source_title' => $this->whenLoaded('knowledgeSource', fn (): ?string => $this->knowledgeSource?->translatedTitle()),
            'knowledge_source' => $this->whenLoaded('knowledgeSource', fn (): ?array => $this->knowledgeSource === null ? null : [
                'id' => $this->knowledgeSource->id, 'title' => $this->knowledgeSource->translatedTitle(),
                'publisher' => $this->knowledgeSource->publisher, 'source_type' => $this->knowledgeSource->source_type,
                'source_url' => $this->knowledgeSource->source_url, 'reference_identifier' => $this->knowledgeSource->reference_identifier,
            ]),
            'region_ids' => $this->whenLoaded('regions', fn (): array => array_map('intval', $this->regions->modelKeys())),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): array => [
                'id' => $region->id, 'parent_id' => $region->parent_id, 'name' => $region->translatedName(),
                'region_type' => $region->region_type, 'is_active' => $region->is_active,
            ])->all()),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

<?php

namespace App\Http\Resources\Admin;

use App\Models\FertilizerRecommendationNutrient;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FertilizerRecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'crop_id' => $this->crop_id, 'crop_variety_id' => $this->crop_variety_id,
            'season_id' => $this->season_id, 'region_scope' => $this->region_scope,
            'irrigation_condition' => $this->irrigation_condition, 'soil_condition' => $this->soil_condition, 'application_method' => $this->application_method,
            'reference_code' => $this->reference_code, 'basal_dose_notes' => $this->basal_dose_notes,
            'top_dressing_notes' => $this->top_dressing_notes, 'general_advisory' => $this->general_advisory,
            'nutrients' => $this->whenLoaded('nutrients', fn (): array => $this->nutrients->map(fn (FertilizerRecommendationNutrient $row): array => [
                'id' => $row->id, 'nutrient_code' => $row->nutrient_code, 'recommended_quantity' => $row->recommended_quantity,
                'unit_code' => $row->unit_code, 'area_unit_code' => $row->area_unit_code,
                'created_at' => $row->created_at?->toISOString(), 'updated_at' => $row->updated_at?->toISOString(),
            ])->all()),
            'recommendation_type' => $this->recommendation_type, 'source_id' => $this->source_id, 'reference_year' => $this->reference_year,
            'notes' => $this->notes, 'is_active' => $this->is_active,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName()),
            'crop_variety_name' => $this->whenLoaded('cropVariety', fn (): ?string => $this->cropVariety?->translatedName()),
            'season_name' => $this->whenLoaded('season', fn (): ?string => $this->season?->translatedName()),
            'crop' => $this->whenLoaded('crop', fn (): ?array => $this->crop === null ? null : ['id' => $this->crop->id, 'name' => $this->crop->translatedName()]),
            'crop_variety' => $this->whenLoaded('cropVariety', fn (): ?array => $this->cropVariety === null ? null : ['id' => $this->cropVariety->id, 'name' => $this->cropVariety->translatedName()]),
            'season' => $this->whenLoaded('season', fn (): ?array => $this->season === null ? null : ['id' => $this->season->id, 'name' => $this->season->translatedName()]),
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

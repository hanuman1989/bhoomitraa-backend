<?php

namespace App\Http\Resources\Account;

use App\Models\CropSeedRate;
use App\Models\CropYieldBenchmark;
use App\Models\FertilizerRecommendation;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegionalRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fields = match (true) {
            $this->resource instanceof CropSeedRate => ['crop_id', 'crop_variety_id', 'seed_id', 'season_id', 'material_type',
                'sowing_method', 'irrigation_condition', 'rate_basis', 'rate_min', 'rate_recommended', 'rate_max', 'rate_unit_code',
                'area_unit_code', 'spacing_row_cm', 'spacing_plant_cm', 'notes'],
            $this->resource instanceof FertilizerRecommendation => ['crop_id', 'crop_variety_id', 'season_id', 'recommendation_type',
                'irrigation_condition', 'soil_condition', 'application_method', 'reference_code', 'reference_year', 'basal_dose_notes',
                'top_dressing_notes', 'general_advisory', 'notes'],
            $this->resource instanceof CropYieldBenchmark => ['crop_id', 'crop_variety_id', 'season_id', 'yield_min_kg_ha',
                'yield_avg_kg_ha', 'yield_max_kg_ha', 'benchmark_type', 'reference_year', 'notes'],
            default => ['seed_id', 'fertilizer_id', 'seller_name', 'price_type', 'package_quantity', 'package_unit_code', 'package_price', 'currency_code'],
        };
        $data = ['id' => $this->id, ...$this->resource->only($fields), 'region_scope' => $this->region_scope,
            'regions' => RegionResource::collection($this->regions),
            ...Region::agriculturalMatch($this->resource, $this->matching_region_ids ?? []),
            'availability_status' => 'available',
            'source' => $this->knowledgeSource === null ? null : ['id' => $this->knowledgeSource->id,
                'title' => $this->knowledgeSource->translatedTitle(), 'publisher' => $this->knowledgeSource->publisher,
                'source_url' => $this->knowledgeSource->source_url, 'reference_identifier' => $this->knowledgeSource->reference_identifier]];
        if ($this->resource instanceof FertilizerRecommendation) {
            $data['nutrients'] = $this->nutrients->map->only(['nutrient_code', 'recommended_quantity', 'unit_code', 'area_unit_code']);
        }
        if (array_key_exists('effective_from', $this->resource->getAttributes())) {
            $data['effective_from'] = $this->effective_from?->toDateString();
            $data['effective_to'] = $this->effective_to?->toDateString();
            $data['last_verified_at'] = $this->last_verified_at?->toISOString();
        }

        return $data;
    }
}

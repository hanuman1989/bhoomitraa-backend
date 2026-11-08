<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropSeedRate> */
class CropSeedRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory()->published(), 'crop_variety_id' => null, 'seed_id' => null, 'season_id' => null,
            'material_type' => 'seed', 'sowing_method' => null, 'irrigation_condition' => null, 'rate_basis' => 'per_area',
            'rate_min' => null, 'rate_recommended' => '4.0000', 'rate_max' => null, 'rate_unit_code' => 'kg', 'area_unit_code' => 'ha',
            'spacing_row_cm' => null, 'spacing_plant_cm' => null, 'region_scope' => 'all',
            'source_id' => KnowledgeSource::factory()->active(), 'notes' => null, 'is_active' => false,
        ];
    }
}

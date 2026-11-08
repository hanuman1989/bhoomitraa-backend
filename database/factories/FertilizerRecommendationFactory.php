<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerRecommendation> */
class FertilizerRecommendationFactory extends Factory
{
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withNutrients(): static
    {
        return $this->has(FertilizerRecommendationNutrient::factory(), 'nutrients');
    }

    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory()->published(), 'crop_variety_id' => null, 'season_id' => null,
            'recommendation_type' => 'general', 'irrigation_condition' => null, 'soil_condition' => null,
            'application_method' => null, 'reference_code' => null, 'region_scope' => 'all',
            'source_id' => KnowledgeSource::factory()->active(), 'reference_year' => null,
            'basal_dose_notes' => null, 'top_dressing_notes' => null, 'general_advisory' => null,
            'notes' => null, 'is_active' => true,
        ];
    }
}

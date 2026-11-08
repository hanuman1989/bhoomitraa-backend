<?php

namespace Database\Factories;

use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerRecommendationNutrient> */
class FertilizerRecommendationNutrientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fertilizer_recommendation_id' => FertilizerRecommendation::factory(),
            'nutrient_code' => 'N', 'recommended_quantity' => '80.0000', 'unit_code' => 'kg', 'area_unit_code' => 'ha',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerNutrient> */
class FertilizerNutrientFactory extends Factory
{
    public function definition(): array
    {
        return ['fertilizer_id' => Fertilizer::factory(), 'nutrient_code' => 'N', 'content_value' => '46.0000', 'content_unit_code' => 'percent_ww'];
    }
}

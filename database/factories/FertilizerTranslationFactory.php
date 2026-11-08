<?php

namespace Database\Factories;

use App\Models\Fertilizer;
use App\Models\FertilizerTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerTranslation> */
class FertilizerTranslationFactory extends Factory
{
    public function definition(): array
    {
        return ['fertilizer_id' => Fertilizer::factory(), 'locale' => 'en', 'name' => fake()->words(2, true), 'description' => null, 'usage_instructions' => null];
    }
}

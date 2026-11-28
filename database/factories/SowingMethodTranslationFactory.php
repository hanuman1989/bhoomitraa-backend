<?php

namespace Database\Factories;

use App\Models\SowingMethod;
use App\Models\SowingMethodTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SowingMethodTranslation> */
class SowingMethodTranslationFactory extends Factory
{
    public function definition(): array
    {
        return ['sowing_method_id' => SowingMethod::factory(), 'locale' => 'en', 'title' => fake()->words(2, true),
            'short_description' => fake()->sentence(), 'description' => fake()->paragraph()];
    }
}

<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropTranslation> */
class CropTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['crop_id' => Crop::factory(), 'locale' => 'en', 'name' => fake()->word(), 'description' => null];
    }
}

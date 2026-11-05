<?php

namespace Database\Factories;

use App\Models\CropVariety;
use App\Models\CropVarietyTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropVarietyTranslation> */
class CropVarietyTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_variety_id' => CropVariety::factory(),
            'locale' => 'en',
            'name' => fake()->word(),
            'description' => null,
            'region_notes' => null,
        ];
    }
}

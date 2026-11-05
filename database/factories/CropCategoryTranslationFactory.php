<?php

namespace Database\Factories;

use App\Models\CropCategory;
use App\Models\CropCategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropCategoryTranslation> */
class CropCategoryTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_category_id' => CropCategory::factory(),
            'locale' => 'en',
            'name' => fake()->words(2, true),
            'description' => null,
        ];
    }
}

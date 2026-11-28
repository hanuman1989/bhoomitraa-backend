<?php

namespace Database\Factories;

use App\Models\CropFieldLayout;
use App\Models\CropFieldLayoutTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropFieldLayoutTranslation> */
class CropFieldLayoutTranslationFactory extends Factory
{
    public function definition(): array
    {
        return ['crop_field_layout_id' => CropFieldLayout::factory(), 'locale' => config('languages.default'),
            'title' => fake()->words(3, true), 'description' => fake()->sentence(), 'important_considerations' => null];
    }
}

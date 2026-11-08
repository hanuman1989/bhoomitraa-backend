<?php

namespace Database\Factories;

use App\Models\CropActivityTemplate;
use App\Models\CropActivityTemplateTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CropActivityTemplateTranslation>
 */
class CropActivityTemplateTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crop_activity_template_id' => CropActivityTemplate::factory(),
            'locale' => 'en',
            'title' => fake()->words(3, true),
            'description' => null,
            'instructions' => null,
        ];
    }
}

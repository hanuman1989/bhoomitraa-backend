<?php

namespace Database\Factories;

use App\Models\ActivityCategory;
use App\Models\ActivityCategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityCategoryTranslation>
 */
class ActivityCategoryTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'activity_category_id' => ActivityCategory::factory(),
            'locale' => 'en',
            'name' => fake()->words(2, true),
            'description' => null,
        ];
    }
}

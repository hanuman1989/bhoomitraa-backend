<?php

namespace Database\Factories;

use App\Models\ActivityCategory;
use App\Models\ActivityCategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityCategory>
 */
class ActivityCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(),
            'icon' => null,
            'is_system' => false,
            'is_active' => false,
        ];
    }

    public function withNames(string $english = 'Irrigation', string $hindi = 'सिंचाई'): static
    {
        return $this->has(ActivityCategoryTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(ActivityCategoryTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }

    public function system(): static
    {
        return $this->state(fn (): array => ['is_system' => true]);
    }

    public function published(): static
    {
        return $this->withNames()->state(fn (): array => ['is_active' => true]);
    }
}

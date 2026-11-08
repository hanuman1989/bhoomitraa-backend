<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\Seed;
use App\Models\SeedTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seed>
 */
class SeedFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory()->published(),
            'crop_variety_id' => null, 'slug' => fake()->unique()->slug(), 'sku' => null,
            'material_type' => 'seed', 'treatment_status' => 'untreated', 'default_unit_code' => 'g',
            'setup_status' => 'draft', 'completed_step' => 1, 'is_active' => false,
        ];
    }

    public function withTranslations(string $english = 'Test seed', string $hindi = 'परीक्षण बीज'): static
    {
        return $this->has(SeedTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(SeedTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }

    public function completed(): static
    {
        return $this->withTranslations()->state(['setup_status' => 'completed', 'completed_step' => 4]);
    }
}

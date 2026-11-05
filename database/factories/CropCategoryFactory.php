<?php

namespace Database\Factories;

use App\Models\CropCategory;
use App\Models\CropCategoryTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropCategory> */
class CropCategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['slug' => fake()->unique()->slug(), 'is_active' => false];
    }

    public function withEnglishName(string $name): static
    {
        return $this->has(CropCategoryTranslation::factory()->state(['locale' => 'en', 'name' => $name]), 'translations');
    }

    public function published(string $name = 'Cereals'): static
    {
        return $this->state(['is_active' => true])
            ->has(CropCategoryTranslation::factory()->state(['locale' => 'en', 'name' => $name]), 'translations');
    }
}

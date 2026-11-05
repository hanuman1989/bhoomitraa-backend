<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\CropVarietyTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropVariety> */
class CropVarietyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory(),
            'slug' => fake()->unique()->slug(),
            'variety_code' => null,
            'maturity_days_min' => null,
            'maturity_days_max' => null,
            'source_id' => null,
            'is_active' => false,
        ];
    }

    public function withNames(string $english = 'HD 2967', string $hindi = 'एचडी 2967'): static
    {
        return $this->has(CropVarietyTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(CropVarietyTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }
}

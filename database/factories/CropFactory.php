<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropCategory;
use App\Models\CropTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Crop> */
class CropFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'category_id' => CropCategory::factory(),
            'slug' => fake()->unique()->slug(),
            'scientific_name' => null,
            'crop_code' => null,
            'image' => null,
            'is_active' => false,
            'ai_diagnosis_enabled' => false,
        ];
    }

    public function withNames(string $english = 'Wheat', string $hindi = 'गेहूं'): static
    {
        return $this->has(CropTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(CropTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }

    public function published(string $english = 'Wheat', string $hindi = 'गेहूं'): static
    {
        return $this->withNames($english, $hindi)->state(['is_active' => true, 'category_id' => CropCategory::factory()->published()]);
    }
}

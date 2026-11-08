<?php

namespace Database\Factories;

use App\Models\Fertilizer;
use App\Models\FertilizerTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fertilizer> */
class FertilizerFactory extends Factory
{
    public function definition(): array
    {
        return ['slug' => fake()->unique()->slug(), 'fertilizer_code' => null, 'fertilizer_type' => 'straight', 'form' => 'granular',
            'default_unit_code' => 'kg', 'setup_status' => 'draft', 'completed_step' => 1, 'verification_status' => 'pending', 'is_active' => false];
    }

    public function withTranslations(string $english = 'Test fertilizer', string $hindi = 'परीक्षण उर्वरक'): static
    {
        return $this->has(FertilizerTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(FertilizerTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }

    public function completed(): static
    {
        return $this->withTranslations()->state(['setup_status' => 'completed', 'completed_step' => 5]);
    }
}

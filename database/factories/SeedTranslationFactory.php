<?php

namespace Database\Factories;

use App\Models\Seed;
use App\Models\SeedTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeedTranslation>
 */
class SeedTranslationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seed_id' => Seed::factory(), 'locale' => 'en', 'name' => fake()->words(3, true), 'description' => null, 'planting_instructions' => null,
        ];
    }
}

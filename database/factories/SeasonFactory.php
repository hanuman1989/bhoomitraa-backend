<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['slug' => fake()->unique()->slug(), 'is_active' => false];
    }

    public function withNames(string $english = 'Rabi', string $hindi = 'रबी'): static
    {
        return $this->has(SeasonTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(SeasonTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }
}

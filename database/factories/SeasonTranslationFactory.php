<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeasonTranslation> */
class SeasonTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'locale' => 'en',
            'name' => fake()->word(),
            'description' => null,
        ];
    }
}

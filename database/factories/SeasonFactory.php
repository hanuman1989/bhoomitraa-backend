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
        return ['slug' => fake()->unique()->slug(), 'is_active' => false, 'start_month' => null, 'end_month' => null, 'icon' => null];
    }

    public function withPresentation(?int $startMonth = 10, ?int $endMonth = 3, ?string $icon = 'sprout'): static
    {
        return $this->state(['start_month' => $startMonth, 'end_month' => $endMonth, 'icon' => $icon]);
    }

    public function withNames(string $english = 'Rabi', string $hindi = 'रबी'): static
    {
        return $this->has(SeasonTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(SeasonTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }
}

<?php

namespace Database\Factories;

use App\Models\Region;
use App\Models\RegionTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegionTranslation> */
class RegionTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['region_id' => Region::factory(), 'locale' => 'en', 'name' => fake()->country()];
    }
}

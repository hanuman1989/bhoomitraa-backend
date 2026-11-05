<?php

namespace Database\Factories;

use App\Models\Region;
use App\Models\RegionTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Region> */
class RegionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['parent_id' => null, 'code' => null, 'region_type' => 'country', 'latitude' => null, 'longitude' => null, 'is_active' => true];
    }

    public function withEnglishName(string $name): static
    {
        return $this->has(RegionTranslation::factory()->state(['locale' => 'en', 'name' => $name]), 'translations');
    }

    public function under(Region $parent): static
    {
        $type = array_search($parent->region_type, Region::PARENT_TYPES, true);

        return $this->for($parent, 'parent')->state(['region_type' => $type]);
    }
}

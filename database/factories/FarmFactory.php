<?php

namespace Database\Factories;

use App\Models\Farm;
use App\Models\Region;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Farm> */
class FarmFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->words(2, true), 'area' => '1.000',
            'area_unit' => config('area_units.default_unit'), 'area_source' => 'government_record', 'is_active' => true];
    }

    public function inDistrict(Region $district): static
    {
        return $this->state(['state_id' => $district->parent_id, 'district_id' => $district->id,
            'region_id' => $district->id, 'village' => 'Test village']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}

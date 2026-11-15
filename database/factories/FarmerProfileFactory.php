<?php

namespace Database\Factories;

use App\Models\FarmerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FarmerProfile> */
class FarmerProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'preferred_language' => 'hi', 'preferred_units' => config('area_units.default_unit')];
    }
}

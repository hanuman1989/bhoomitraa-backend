<?php

namespace Database\Factories;

use App\Models\SowingMethod;
use App\Models\SowingMethodImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SowingMethodImage> */
class SowingMethodImageFactory extends Factory
{
    public function definition(): array
    {
        return ['sowing_method_id' => SowingMethod::factory(),
            'image' => fn (array $attributes): string => 'sowing-methods/'.$attributes['sowing_method_id'].'/'.fake()->uuid().'.jpg',
            'sort_order' => 1];
    }
}

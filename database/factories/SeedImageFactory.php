<?php

namespace Database\Factories;

use App\Models\Seed;
use App\Models\SeedImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeedImage>
 */
class SeedImageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seed_id' => Seed::factory(), 'image_path' => 'seeds/example.jpg', 'alt_text' => null, 'sort_order' => 1,
        ];
    }
}

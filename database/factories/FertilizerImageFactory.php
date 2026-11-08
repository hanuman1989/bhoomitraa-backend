<?php

namespace Database\Factories;

use App\Models\Fertilizer;
use App\Models\FertilizerImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerImage> */
class FertilizerImageFactory extends Factory
{
    public function definition(): array
    {
        return ['fertilizer_id' => Fertilizer::factory(), 'image_path' => 'fertilizers/test.jpg', 'alt_text' => null, 'sort_order' => 1];
    }
}

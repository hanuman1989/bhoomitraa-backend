<?php

namespace Database\Factories;

use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedLot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeedLot>
 */
class SeedLotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seed_id' => Seed::factory()->completed()->state([
                'is_active' => true, 'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active(),
            ]),
            'lot_number' => fake()->unique()->bothify('LOT-########'),
            'verification_status' => 'pending', 'is_active' => true,
        ];
    }
}

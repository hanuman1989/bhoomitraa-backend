<?php

namespace Database\Factories;

use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeedPrice>
 */
class SeedPriceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'seed_id' => Seed::factory()->completed()->state(['is_active' => true, 'verification_status' => 'verified', 'source_id' => KnowledgeSource::factory()->active()]),
            'seller_name' => null, 'price_type' => 'retail', 'package_quantity' => '1.0000', 'package_unit_code' => 'kg',
            'currency_code' => config('seed_prices.default_currency'), 'package_price' => '200.0000', 'region_scope' => 'all',
            'effective_from' => '2026-11-07', 'effective_to' => null, 'source_id' => null, 'is_active' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Fertilizer;
use App\Models\FertilizerPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FertilizerPrice> */
class FertilizerPriceFactory extends Factory
{
    public function definition(): array
    {
        return ['fertilizer_id' => Fertilizer::factory(), 'seller_name' => null, 'price_type' => 'retail',
            'package_quantity' => '1.0000', 'package_unit_code' => 'kg', 'currency_code' => config('seed_prices.default_currency'),
            'package_price' => '100.0000', 'region_scope' => 'all', 'effective_from' => today()->toDateString(),
            'effective_to' => null, 'source_id' => null, 'is_active' => false];
    }
}

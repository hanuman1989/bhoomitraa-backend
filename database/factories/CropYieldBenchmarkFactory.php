<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropYieldBenchmark;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropYieldBenchmark> */
class CropYieldBenchmarkFactory extends Factory
{
    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory()->published(), 'crop_variety_id' => null, 'season_id' => null,
            'region_scope' => 'all', 'yield_min_kg_ha' => null, 'yield_avg_kg_ha' => '2000.000', 'yield_max_kg_ha' => null,
            'benchmark_type' => 'research_trial', 'source_id' => KnowledgeSource::factory()->active(),
            'reference_year' => null, 'notes' => null, 'is_active' => true,
        ];
    }
}

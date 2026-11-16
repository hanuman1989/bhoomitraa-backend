<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\Farm;
use App\Models\FarmCropCycle;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FarmCropCycle> */
class FarmCropCycleFactory extends Factory
{
    public function definition(): array
    {
        return ['farm_id' => Farm::factory(), 'crop_id' => Crop::factory()->published(), 'season_id' => Season::factory()->withNames()->state(['is_active' => true]),
            'created_by' => fn (array $attributes): int => Farm::findOrFail($attributes['farm_id'])->user_id,
            'creation_key' => hash('sha256', (string) Str::uuid()), 'creation_payload_hash' => hash('sha256', (string) Str::uuid()),
            'status' => 'draft', 'completed_step' => 1, 'revision' => 1,
            'wizard_progress' => array_replace(array_fill_keys(FarmCropCycle::STEPS, 'pending'), ['crop_selection' => 'completed'])];
    }
}

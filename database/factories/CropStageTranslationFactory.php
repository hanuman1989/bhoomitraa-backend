<?php

namespace Database\Factories;

use App\Models\CropStage;
use App\Models\CropStageTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropStageTranslation> */
class CropStageTranslationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_stage_id' => CropStage::factory(),
            'locale' => 'en',
            'name' => fake()->word(),
            'description' => null,
        ];
    }
}

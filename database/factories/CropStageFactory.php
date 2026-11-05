<?php

namespace Database\Factories;

use App\Models\Crop;
use App\Models\CropStage;
use App\Models\CropStageTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropStage> */
class CropStageFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory(),
            'code' => fake()->unique()->slug(2),
            'sequence' => 1,
            'is_active' => false,
        ];
    }

    public function withNames(string $english = 'Example stage', string $hindi = 'उदाहरण अवस्था'): static
    {
        return $this->has(CropStageTranslation::factory()->state(['locale' => 'en', 'name' => $english]), 'translations')
            ->has(CropStageTranslation::factory()->state(['locale' => 'hi', 'name' => $hindi]), 'translations');
    }
}

<?php

namespace Database\Factories;

use App\Models\CropFieldLayout;
use App\Models\CropFieldLayoutTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropFieldLayout> */
class CropFieldLayoutFactory extends Factory
{
    public function definition(): array
    {
        return ['layout_type' => 'random_distribution', 'crop_scope' => 'all', 'configuration' => (object) [],
            'configuration_version' => 1, 'sort_order' => 0, 'is_active' => false,
            'wizard_draft' => ['is_edit' => false, 'completed_steps' => [1], 'requested_is_active' => false, 'revision' => 1]];
    }

    public function finalized(): static
    {
        return $this->state(['wizard_draft' => null])->afterCreating(function (CropFieldLayout $layout): void {
            foreach (array_keys(config('languages.supported')) as $locale) {
                CropFieldLayoutTranslation::factory()->for($layout)->create(['locale' => $locale]);
            }
        });
    }

    public function active(): static
    {
        return $this->finalized()->state(['is_active' => true]);
    }
}

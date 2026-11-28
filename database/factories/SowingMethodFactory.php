<?php

namespace Database\Factories;

use App\Models\SowingMethod;
use App\Models\SowingMethodTranslation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SowingMethod> */
class SowingMethodFactory extends Factory
{
    public function definition(): array
    {
        $code = fake()->unique()->bothify('method_????????');

        return ['code' => $code, 'slug' => str_replace('_', '-', $code), 'establishment_type' => 'direct_sowing', 'crop_scope' => 'all'];
    }

    public function withTranslations(): static
    {
        return $this->has(SowingMethodTranslation::factory()->state(['locale' => 'en']), 'translations')
            ->has(SowingMethodTranslation::factory()->state(['locale' => 'hi', 'title' => 'कतार बुवाई',
                'short_description' => 'कतारों में बुवाई की विधि।', 'description' => 'बुवाई विधि का विस्तृत विवरण।']), 'translations');
    }

    public function active(): static
    {
        return $this->withTranslations()->state(['is_active' => true]);
    }
}

<?php

namespace Database\Factories;

use App\Models\ActivityCategory;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropActivityTemplateTranslation;
use App\Models\KnowledgeSource;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CropActivityTemplate>
 */
class CropActivityTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crop_id' => Crop::factory()->published(),
            'crop_variety_id' => null,
            'category_id' => ActivityCategory::factory()->published(),
            'crop_stage_id' => null,
            'season_id' => Season::factory()->withNames(),
            'schedule_type' => 'after_sowing',
            'start_offset_days' => 0,
            'end_offset_days' => 10,
            'region_scope' => 'all',
            'priority' => 'medium',
            'source_id' => KnowledgeSource::factory(),
            'is_active' => false,
        ];
    }

    public function withTitles(string $english = 'Example activity', string $hindi = 'उदाहरण गतिविधि'): static
    {
        return $this->has(CropActivityTemplateTranslation::factory()->state([
            'locale' => 'en', 'title' => $english, 'instructions' => 'Illustrative test instructions.',
        ]), 'translations')->has(CropActivityTemplateTranslation::factory()->state([
            'locale' => 'hi', 'title' => $hindi, 'instructions' => 'परीक्षण के उदाहरण निर्देश।',
        ]), 'translations');
    }
}

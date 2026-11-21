<?php

namespace Database\Factories;

use App\Models\ActivityCategory;
use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropActivityTemplateTranslation;
use App\Models\KnowledgeSource;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

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
        $attributes = [
            'crop_id' => Crop::factory()->published(),
            'category_id' => ActivityCategory::factory()->published(),
            'crop_stage_id' => null,
            'variety_scope' => 'all',
            'season_scope' => 'specific',
            'schedule_type' => 'after_sowing',
            'schedule_basis' => 'fixed_offsets',
            'start_offset_days' => 0,
            'end_offset_days' => 10,
            'region_scope' => 'all',
            'activity_type' => 'task',
            'allow_status_update' => true,
            'allow_expenses' => false,
            'allow_labour' => false,
            'allow_notes' => true,
            'allow_attachments' => false,
            'allow_rescheduling' => false,
            'smart_actions' => [],
            'trigger_type' => 'scheduled',
            'trigger_config' => null,
            'priority' => 'medium',
            'is_active' => false,
        ];
        if (Schema::hasColumn('crop_activity_templates', 'season_id')) {
            $attributes['season_id'] = Season::factory()->withNames();
        }
        if (Schema::hasColumn('crop_activity_templates', 'source_id')) {
            $attributes['source_id'] = KnowledgeSource::factory();
        }
        if (Schema::hasColumn('crop_activity_templates', 'crop_variety_id')) {
            $attributes['crop_variety_id'] = null;
        }

        return $attributes;
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

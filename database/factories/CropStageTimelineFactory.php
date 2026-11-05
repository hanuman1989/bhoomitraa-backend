<?php

namespace Database\Factories;

use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CropStageTimeline> */
class CropStageTimelineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'crop_stage_id' => CropStage::factory()->withNames(),
            'crop_variety_id' => null,
            'season_id' => null,
            'region_scope' => 'all',
            'days_after_sowing_min' => 0,
            'days_after_sowing_max' => 10,
            'source_id' => KnowledgeSource::factory(),
            'is_active' => false,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;

/** @extends Factory<CropStageTimeline> */
class CropStageTimelineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $attributes = [
            'crop_stage_id' => CropStage::factory()->withNames(),
            'variety_scope' => 'all',
            'season_scope' => 'all',
            'region_scope' => 'all',
            'days_after_sowing_min' => 0,
            'days_after_sowing_max' => 10,
            'is_active' => false,
        ];
        if (Schema::hasColumn('crop_stage_timelines', 'source_id')) {
            $attributes['source_id'] = KnowledgeSource::factory();
        }
        if (Schema::hasColumn('crop_stage_timelines', 'season_id')) {
            $attributes['season_id'] = null;
        }
        if (Schema::hasColumn('crop_stage_timelines', 'crop_variety_id')) {
            $attributes['crop_variety_id'] = null;
        }

        return $attributes;
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStageTimeline;

class UpdateCropStageTimelineRequest extends StoreCropStageTimelineRequest
{
    public function authorize(): bool
    {
        $timeline = $this->route('cropStageTimeline');

        return parent::authorize() && (
            ! $timeline instanceof CropStageTimeline || ! $timeline->is_active
            || ($this->user()?->can('review-knowledge-sources') ?? false)
        );
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['crop_stage_id', 'region_scope', 'days_after_sowing_min', 'days_after_sowing_max', 'source_id'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

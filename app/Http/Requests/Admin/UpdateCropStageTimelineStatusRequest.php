<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStageTimeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCropStageTimelineStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && (! $this->boolean('is_active') || ($this->user()?->can('review-knowledge-sources') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['is_active', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active')) {
                return;
            }

            $timeline = $this->route('cropStageTimeline');
            if (! $timeline instanceof CropStageTimeline) {
                return;
            }
            $candidate = clone $timeline;
            $candidate->is_active = true;
            foreach ($candidate->validationErrors($timeline->resolveRegionIds([])) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

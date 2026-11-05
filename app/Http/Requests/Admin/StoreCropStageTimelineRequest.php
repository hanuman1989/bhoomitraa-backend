<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStageTimeline;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropStageTimelineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'crop_stage_id' => ['required', 'integer', 'min:1', Rule::exists('crop_stages', 'id')],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'season_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'region_scope' => ['required', 'string', Rule::in(CropStageTimeline::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('regions', 'id')->where('region_type', 'district')],
            'days_after_sowing_min' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'days_after_sowing_max' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'source_id' => ['required', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metadataFields = array_diff((new CropStageTimeline)->getFillable(), ['is_active']);
            foreach (array_diff(array_keys($this->all()), [...$metadataFields, 'region_ids', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $timeline = $this->route('cropStageTimeline');
            $timeline = $timeline instanceof CropStageTimeline ? $timeline : new CropStageTimeline;
            $data = $validator->getData();
            $regionIds = $timeline->resolveRegionIds($data);
            $candidate = clone $timeline;
            $candidate->fill(Arr::only($data, $metadataFields));
            if (! $candidate->exists) {
                $candidate->is_active = false;
            }
            foreach ($candidate->validationErrors($regionIds) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'region_ids.*.exists' => 'Select an existing district.',
            'region_ids.*.distinct' => 'Each district may only be selected once.',
        ];
    }
}

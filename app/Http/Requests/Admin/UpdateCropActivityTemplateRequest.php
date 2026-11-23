<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;

class UpdateCropActivityTemplateRequest extends StoreCropActivityTemplateRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $template = $this->route('cropActivityTemplate');

        return parent::authorize() && (! $template instanceof CropActivityTemplate || ! $template->is_active
            || ($this->user()?->can('review-knowledge-sources') ?? false));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach ([
            'crop_id', 'category_id', 'crop_stage_id', 'variety_scope', 'crop_variety_ids', 'season_scope',
            'season_ids', 'schedule_type', 'schedule_basis', 'start_offset_days', 'end_offset_days',
            'activity_type', 'allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes',
            'allow_attachments', 'allow_rescheduling', 'smart_actions', 'knowledge_source_ids',
            'region_scope', 'region_ids', 'priority', 'translations', 'display_order', 'timing_exception_reason',
        ] as $field) {
            if (! isset($rules[$field])) {
                continue;
            }
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['translations.*.title'] = ['sometimes', 'required', 'string', 'max:200'];
        foreach (CropActivityTemplate::REQUIRED_LOCALES as $locale) {
            array_unshift($rules['translations.'.$locale], 'sometimes');
            array_unshift($rules['translations.'.$locale.'.title'], 'sometimes');
        }
        unset($rules['creation_request_id']);
        $rules['expected_version'] = ['sometimes', 'integer', 'min:1'];

        return $rules;
    }

    /** @return list<string> */
    protected function permittedFields(): array
    {
        return [...array_diff(parent::permittedFields(), ['creation_request_id']), 'expected_version'];
    }
}

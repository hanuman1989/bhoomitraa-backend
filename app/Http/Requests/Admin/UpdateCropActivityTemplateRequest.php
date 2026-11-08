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
        foreach (['crop_id', 'category_id', 'season_id', 'schedule_type', 'start_offset_days', 'end_offset_days', 'region_scope', 'source_id', 'translations'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['translations.*.title'] = ['sometimes', 'required', 'string', 'max:200'];
        foreach (CropActivityTemplate::REQUIRED_LOCALES as $locale) {
            array_unshift($rules['translations.'.$locale], 'sometimes');
            array_unshift($rules['translations.'.$locale.'.title'], 'sometimes');
        }

        return $rules;
    }
}

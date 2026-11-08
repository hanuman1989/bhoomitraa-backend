<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropActivityTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'crop_id' => ['required', 'integer', 'min:1'],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'category_id' => ['required', 'integer', 'min:1'],
            'crop_stage_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'season_id' => ['required', 'integer', 'min:1'],
            'schedule_type' => ['required', 'string', Rule::in(CropActivityTemplate::SCHEDULE_TYPES)],
            'start_offset_days' => ['required', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'end_offset_days' => ['required', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'region_scope' => ['required', 'string', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(CropActivityTemplate::PRIORITIES)],
            'source_id' => ['required', 'integer', 'min:1'],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:title,description,instructions'],
            'translations.*.title' => ['required', 'string', 'max:200'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'translations.*.instructions' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
        foreach (CropActivityTemplate::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:title,description,instructions'];
            $rules['translations.'.$locale.'.title'] = ['required', 'string', 'max:200'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metadata = array_diff((new CropActivityTemplate)->getFillable(), ['is_active']);
            foreach (array_diff(array_keys($this->all()), [...$metadata, 'region_ids', 'translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = $this->route('cropActivityTemplate');
            $template = $existing instanceof CropActivityTemplate ? clone $existing : new CropActivityTemplate;
            $data = $validator->getData();
            $regionIds = $template->resolveRegionIds($data);
            $template->fill(Arr::only($data, $metadata));
            foreach ($template->validationErrors($regionIds, $data['translations'] ?? []) as $field => $messages) {
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
            'translations.en.title.required' => 'The English activity title is required.',
            'translations.hi.title.required' => 'The Hindi activity title is required.',
            'region_ids.*.distinct' => 'Each district may only be selected once.',
        ];
    }
}

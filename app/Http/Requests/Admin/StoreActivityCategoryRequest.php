<?php

namespace App\Http\Requests\Admin;

use App\Models\ActivityCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreActivityCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-activity-categories') ?? false)
            && (! $this->boolean('is_system') || ($this->user()?->can('manage-system-activity-categories') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('activity_categories', 'slug')],
            'icon' => ['sometimes', 'nullable', 'string', 'max:100', Rule::in(ActivityCategory::SUPPORTED_ICONS)],
            'is_system' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:150'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
        foreach (ActivityCategory::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:name,description'];
            $rules['translations.'.$locale.'.name'] = ['required', 'string', 'max:150'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['slug', 'icon', 'is_system', 'translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.name.required' => 'The English activity category name is required.',
            'translations.hi.name.required' => 'The Hindi activity category name is required.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
            'icon.in' => 'The selected icon is not supported.',
        ];
    }
}

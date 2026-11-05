<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCropCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-categories') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('crop_categories', 'slug')->ignore($this->route('cropCategory'))],
            'translations' => ['sometimes', 'required', 'array:'.implode(',', array_keys(config('languages.supported'))), 'min:1'],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.'];
    }
}

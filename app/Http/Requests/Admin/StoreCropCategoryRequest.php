<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCropCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-categories') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('translations.en.name');
        if (! $this->filled('slug') && is_string($name)) {
            $this->merge(['slug' => Str::slug($name)]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('crop_categories', 'slug')],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported'))), 'min:1'],
            'translations.en' => ['required', 'array:name,description'],
            'translations.en.name' => ['required', 'string', 'max:255'],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.name.required' => 'The English category name is required.',
            'slug.required' => 'Supply a slug or an English category name that can generate a slug.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
        ];
    }
}

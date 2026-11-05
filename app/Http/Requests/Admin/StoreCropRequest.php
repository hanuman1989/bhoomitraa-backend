<?php

namespace App\Http\Requests\Admin;

use App\Models\Crop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreCropRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && (! $this->boolean('ai_diagnosis_enabled') || ($this->user()?->can('manage-crop-ai-diagnosis') ?? false));
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('crop') instanceof Crop) {
            return;
        }
        $name = $this->input('translations.en.name');
        if (! $this->filled('slug') && is_string($name)) {
            $this->merge(['slug' => Str::slug($name)]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'category_id' => ['required', 'integer', Rule::exists('crop_categories', 'id')],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('crops', 'slug')],
            'scientific_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'crop_code' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('crops', 'crop_code')],
            'image' => ['sometimes', 'nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->extensions(['jpg', 'jpeg', 'png', 'webp'])->max('5mb')],
            'is_active' => ['sometimes', 'boolean'],
            'ai_diagnosis_enabled' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported'))), 'min:1'],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
        foreach (Crop::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:name,description'];
            $rules['translations.'.$locale.'.name'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...(new Crop)->getFillable(), 'translations', 'image', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('ai_diagnosis_enabled') && ! config('crops.ai_diagnosis_available')) {
                $validator->errors()->add('ai_diagnosis_enabled', 'AI diagnosis is not available.');
            }
            $existing = $this->route('crop');
            $crop = $existing instanceof Crop ? clone $existing : new Crop;
            $crop->fill(Arr::only($validator->getData(), $crop->getFillable()));
            foreach ($crop->activationErrors($validator->getData()['translations'] ?? []) as $field => $messages) {
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
            'translations.en.name.required' => 'The English crop name is required.',
            'translations.hi.name.required' => 'The Hindi crop name is required.',
            'slug.required' => 'Supply a slug or an English crop name that can generate a slug.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
        ];
    }
}

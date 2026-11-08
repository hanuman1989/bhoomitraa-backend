<?php

namespace App\Http\Requests\Admin;

use App\Models\Fertilizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveFertilizerTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fertilizers') ?? false;
    }

    public function rules(): array
    {
        return [
            'translations' => ['required', 'array:'.implode(',', Fertilizer::requiredLocales()), 'min:1'],
            'translations.*' => ['required', 'array:name,description,usage_instructions'],
            'translations.*.name' => ['sometimes', 'required', 'string', 'max:200'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'translations.*.usage_instructions' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

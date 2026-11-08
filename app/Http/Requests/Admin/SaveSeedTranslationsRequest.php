<?php

namespace App\Http\Requests\Admin;

use App\Models\Seed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSeedTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-seeds') ?? false;
    }

    public function rules(): array
    {
        return [
            'translations' => ['required', 'array:'.implode(',', Seed::requiredLocales()), 'min:1'],
            'translations.*' => ['required', 'array:name,description,planting_instructions'],
            'translations.*.name' => ['sometimes', 'required', 'string', 'max:200'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'translations.*.planting_instructions' => ['sometimes', 'nullable', 'string', 'max:65535'],
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

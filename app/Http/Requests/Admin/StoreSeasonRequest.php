<?php

namespace App\Http\Requests\Admin;

use App\Models\Season;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSeasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-seasons') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $locales = implode(',', array_keys(config('languages.supported')));

        $rules = [
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('seasons', 'slug')],
            'translations' => ['required', 'array:'.$locales],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (Season::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:name,description'];
            $rules['translations.'.$locale.'.name'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['slug', 'translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.name.required' => 'The English season name is required.',
            'translations.hi.name.required' => 'The Hindi season name is required.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
        ];
    }
}

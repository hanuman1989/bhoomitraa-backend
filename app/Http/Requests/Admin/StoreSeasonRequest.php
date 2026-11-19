<?php

namespace App\Http\Requests\Admin;

use App\Models\Season;
use Closure;
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
            'icon' => ['sometimes', 'nullable', 'string', 'max:30', Rule::in(Season::ICONS)],
            'translations' => ['required', 'array:'.$locales],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (['start_month', 'end_month'] as $field) {
            $rules[$field] = ['bail', 'sometimes', 'nullable', 'integer', 'between:1,12',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_bool($value)) {
                        $fail('The '.str_replace('_', ' ', $attribute).' must be an integer.');
                    }
                }];
        }

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
            foreach (array_diff(array_keys($this->all()), ['slug', ...Season::PRESENTATION_FIELDS, 'translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = $this->route('season');
            $season = $existing instanceof Season ? clone $existing : new Season;
            $season->fill($this->only(Season::PRESENTATION_FIELDS));
            foreach ($season->presentationErrors() as $field => $messages) {
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
            'translations.en.name.required' => 'The English season name is required.',
            'translations.hi.name.required' => 'The Hindi season name is required.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
            'start_month.between' => 'The start month must be between 1 and 12.',
            'end_month.between' => 'The end month must be between 1 and 12.',
            'icon.in' => 'The selected season icon is invalid.',
        ];
    }
}

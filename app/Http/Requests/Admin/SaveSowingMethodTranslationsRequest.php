<?php

namespace App\Http\Requests\Admin;

use App\Models\SowingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSowingMethodTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-sowing-methods') ?? false;
    }

    /** Shared by persistence and finalization to validate merged saved content. @return array<string, array<mixed>> */
    public static function fieldRules(bool $complete = false): array
    {
        $rules = [
            'translations' => ['required', 'array:'.implode(',', SowingMethod::requiredLocales()), 'min:1'],
            'translations.*' => ['required', 'array:'.implode(',', SowingMethod::TRANSLATED_FIELDS), 'min:1'],
        ];
        foreach (SowingMethod::TRANSLATED_FIELDS as $field) {
            $required = $complete && in_array($field, SowingMethod::REQUIRED_TRANSLATED_FIELDS, true);
            $limit = match ($field) {
                'title' => 200, 'short_description' => 500, default => SowingMethod::MAX_TEXT_LENGTH,
            };
            $rules['translations.*.'.$field] = [$required ? 'required' : 'sometimes', $field === 'title' ? 'required' : ($required ? 'required' : 'nullable'), 'string', 'max:'.$limit];
        }
        if ($complete) {
            foreach (SowingMethod::requiredLocales() as $locale) {
                $rules['translations.'.$locale] = ['required', 'array'];
            }
        }

        return $rules;
    }

    public function rules(): array
    {
        return self::fieldRules();
    }

    protected function prepareForValidation(): void
    {
        $translations = $this->input('translations');
        if (! is_array($translations)) {
            return;
        }
        foreach ($translations as &$fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as &$value) {
                if (is_string($value)) {
                    $value = preg_replace('/^\s+|\s+$/u', '', $value);
                    $value = $value === '' ? null : $value;
                }
            }
            unset($value);
        }
        unset($fields);
        $this->merge(['translations' => $translations]);
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

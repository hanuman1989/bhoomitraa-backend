<?php

namespace App\Http\Requests\Admin;

use App\Models\CropFieldLayout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveCropFieldLayoutTranslationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-field-layouts') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public static function fieldRules(bool $complete = false): array
    {
        $rules = [
            'translations' => ['required', $complete ? 'array' : 'array:'.implode(',', array_keys(config('languages.supported'))), 'min:1'],
            'translations.*' => ['required', 'array:'.implode(',', CropFieldLayout::TRANSLATED_FIELDS), 'min:1'],
            'translations.*.title' => ['sometimes', 'required', 'string', 'max:200'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'translations.*.important_considerations' => ['sometimes', 'nullable', 'string', 'max:16000'],
        ];
        if ($complete) {
            foreach (array_keys(config('languages.supported')) as $locale) {
                $rules['translations.'.$locale.'.title'] = ['required', 'string', 'max:200'];
                $rules['translations.'.$locale.'.description'] = ['required', 'string', 'max:16000'];
            }
        }

        return $rules;
    }

    public function rules(): array
    {
        return [...self::fieldRules(), 'draft_revision' => ['sometimes', 'required', 'integer', 'min:0']];
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
        return [fn (Validator $validator) => StoreCropFieldLayoutRequest::rejectUnexpected($this, $validator, ['translations', 'draft_revision'])];
    }
}

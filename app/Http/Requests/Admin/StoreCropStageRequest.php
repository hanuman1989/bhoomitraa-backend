<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = [
            'crop_id' => ['required', 'integer', Rule::exists('crops', 'id')],
            'code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('crop_stages', 'code')->where('crop_id', $this->integer('crop_id'))],
            'sequence' => ['required', 'integer', 'min:1', 'max:65535',
                Rule::unique('crop_stages', 'sequence')->where('crop_id', $this->integer('crop_id'))],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:name,description'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string'],
        ];

        foreach (CropStage::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:name,description'];
            $rules['translations.'.$locale.'.name'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['crop_id', 'code', 'sequence', 'translations', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.name.required' => 'The English stage name is required.',
            'translations.hi.name.required' => 'The Hindi stage name is required.',
            'code.regex' => 'The code must contain lowercase letters, numbers, and single hyphens between words.',
            'code.unique' => 'The code is already used by another stage of this crop.',
            'sequence.unique' => 'The sequence is already used by another stage of this crop.',
        ];
    }
}

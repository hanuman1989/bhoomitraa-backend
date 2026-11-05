<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStage;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCropStageRequest extends StoreCropStageRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $stage = $this->route('cropStage');
        $cropId = $stage instanceof CropStage ? $stage->crop_id : null;
        $rules = parent::rules();
        $rules['crop_id'] = ['sometimes', 'required', 'integer', Rule::exists('crops', 'id'), Rule::in([$cropId])];
        $rules['code'] = ['sometimes', 'required', 'string', 'max:50', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('crop_stages', 'code')->where('crop_id', $cropId)->ignore($stage)];
        $rules['sequence'] = ['sometimes', 'required', 'integer', 'min:1', 'max:65535',
            Rule::unique('crop_stages', 'sequence')->where('crop_id', $cropId)->ignore($stage)];
        array_unshift($rules['translations'], 'sometimes');
        $rules['translations.*.name'] = ['sometimes', 'required', 'string', 'max:255'];
        foreach (CropStage::REQUIRED_LOCALES as $locale) {
            array_unshift($rules['translations.'.$locale], 'sometimes');
            array_unshift($rules['translations.'.$locale.'.name'], 'sometimes');
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $stage = $this->route('cropStage');
            if (! $stage instanceof CropStage) {
                return;
            }

            $data = $validator->getData();
            $translations = $data['translations'] ?? [];
            if ($translations !== []) {
                $existingLocales = $stage->translations()->pluck('locale')->all();
                foreach ($translations as $locale => $values) {
                    if (! array_key_exists('name', $values) && ! in_array($locale, $existingLocales, true)) {
                        $validator->errors()->add('translations.'.$locale.'.name', 'A name is required when adding a stage translation.');
                    }
                }
            }

            $candidate = clone $stage;
            $candidate->fill(Arr::only($data, ['code', 'sequence']));
            foreach ($candidate->activationErrors($translations) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...parent::messages(), 'crop_id.in' => 'The parent crop cannot be changed after creating a growth stage.'];
    }
}

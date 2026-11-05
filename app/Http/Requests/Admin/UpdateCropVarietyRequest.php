<?php

namespace App\Http\Requests\Admin;

use App\Models\CropVariety;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCropVarietyRequest extends StoreCropVarietyRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $cropVariety = $this->route('cropVariety');
        $cropId = $this->input('crop_id', $cropVariety instanceof CropVariety ? $cropVariety->crop_id : null);

        foreach (['crop_id', 'slug', 'translations'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        $rules['crop_id'] = ['sometimes', 'required', 'integer', Rule::exists('crops', 'id')];
        $rules['slug'] = ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('crop_varieties', 'slug')->where('crop_id', $cropId)->ignore($cropVariety)];
        $rules['variety_code'] = ['sometimes', 'nullable', 'string', 'max:50',
            Rule::unique('crop_varieties', 'variety_code')->where('crop_id', $cropId)->ignore($cropVariety)];
        $rules['translations.*.name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['translations.en'] = ['sometimes', 'required', 'array:name,description,region_notes'];
        $rules['translations.hi'] = ['sometimes', 'required', 'array:name,description,region_notes'];
        $rules['translations.en.name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['translations.hi.name'] = ['sometimes', 'required', 'string', 'max:255'];

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $cropVariety = $this->route('cropVariety');
                if (! $cropVariety instanceof CropVariety) {
                    return;
                }

                $allowed = [...$cropVariety->getFillable(), 'translations', '_method'];
                foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                    $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $data = $validator->getData();
                $attributes = Arr::only($data, $cropVariety->getFillable());
                $cropVariety->fill($attributes);

                if (isset($attributes['crop_id'])
                    && (int) $attributes['crop_id'] !== (int) $cropVariety->getOriginal('crop_id')
                    && $cropVariety->hasExternalReferences()) {
                    $validator->errors()->add('crop_id', 'The parent crop cannot change while this variety is referenced by other records.');
                }

                $translations = $data['translations'] ?? [];
                foreach ($translations as $locale => $values) {
                    if (array_key_exists('name', $values)) {
                        continue;
                    }

                    if (! $cropVariety->translations()->where('locale', $locale)->whereNotNull('name')->exists()) {
                        $validator->errors()->add('translations.'.$locale.'.name', 'A name is required when adding a variety translation.');
                    }
                }

                $minimum = $cropVariety->maturity_days_min;
                $maximum = $cropVariety->maturity_days_max;
                if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
                    $validator->errors()->add('maturity_days_max', 'The maximum maturity days must be greater than or equal to the minimum maturity days.');
                }

                foreach ($cropVariety->publicationErrors($translations) as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }
}

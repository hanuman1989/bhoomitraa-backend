<?php

namespace App\Http\Requests\Admin;

use App\Models\Crop;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCropRequest extends StoreCropRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && (! $this->exists('ai_diagnosis_enabled') || ($this->user()?->can('manage-crop-ai-diagnosis') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['category_id', 'slug', 'translations'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules['slug'] = ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('crops', 'slug')->ignore($this->route('crop'))];
        $rules['crop_code'] = ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('crops', 'crop_code')->ignore($this->route('crop'))];
        foreach (Crop::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['sometimes', 'required', 'array:name,description'];
            $rules['translations.'.$locale.'.name'] = ['required_with:translations.'.$locale, 'string', 'max:255'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->getRealMethod() !== 'POST'
                    && str_starts_with(strtolower($this->header('Content-Type', '')), 'multipart/form-data')) {
                    $validator->errors()->add('_method', 'Send form-data updates using POST with _method set to PUT or PATCH, or send a JSON body with PUT or PATCH.');
                }
            },
            ...parent::after(),
        ];
    }
}

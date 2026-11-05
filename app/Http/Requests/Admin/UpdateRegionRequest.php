<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

class UpdateRegionRequest extends StoreRegionRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $default = config('languages.default');
        $rules = parent::rules();
        foreach (['parent_id', 'code', 'region_type', 'latitude', 'longitude', 'is_active', 'translations'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        $rules["translations.{$default}"] = ['sometimes', 'required', 'array:name'];
        $rules["translations.{$default}.name"] = ["required_with:translations.{$default}", 'string', 'max:150'];

        return $rules;
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\Region;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-regions') ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $default = config('languages.default');

        return [
            'parent_id' => ['nullable', 'integer', Rule::exists('regions', 'id')],
            'code' => ['nullable', 'string', 'max:50'],
            'region_type' => ['required', Rule::in(array_keys(Region::PARENT_TYPES))],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'is_active' => ['required', 'boolean'],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            "translations.{$default}" => ['required', 'array:name'],
            "translations.{$default}.name" => ['required', 'string', 'max:150'],
            'translations.*' => ['required', 'array:name'],
            'translations.*.name' => ['required', 'string', 'max:150'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $existing = $this->route('region');
                $region = $existing instanceof Region ? clone $existing : new Region;
                $region->fill(Arr::only($validator->getData(), $region->getFillable()));
                foreach ($region->hierarchyErrors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $default = config('languages.default');
        $language = config('languages.supported')[$default];

        return ["translations.{$default}.name.required" => "The {$language} region name is required."];
    }
}

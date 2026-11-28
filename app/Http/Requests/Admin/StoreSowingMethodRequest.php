<?php

namespace App\Http\Requests\Admin;

use App\Models\SowingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSowingMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-sowing-methods') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public static function fieldRules(?SowingMethod $method = null, bool $partial = false): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/D', Rule::unique('sowing_methods', 'code')->ignore($method?->id)],
            'slug' => ['required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('sowing_methods', 'slug')->ignore($method?->id)],
            'establishment_type' => ['required', Rule::in(SowingMethod::ESTABLISHMENT_TYPES)],
            'crop_scope' => ['required', Rule::in(SowingMethod::CROP_SCOPES)],
            'crop_ids' => ['sometimes', 'array', 'list', 'max:1000'],
            'crop_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('crops', 'id')],
            'supports_row_spacing' => ['sometimes', 'nullable', 'boolean'],
            'supports_plant_spacing' => ['sometimes', 'nullable', 'boolean'],
            'supports_field_layout' => ['sometimes', 'nullable', 'boolean'],
            'machinery_requirement' => ['sometimes', 'required', Rule::in(SowingMethod::MACHINERY_REQUIREMENTS)],
            'labour_requirement' => ['sometimes', 'required', Rule::in(SowingMethod::LABOUR_REQUIREMENTS)],
            'cost_level' => ['sometimes', 'required', Rule::in(SowingMethod::COST_LEVELS)],
            'sort_order' => ['sometimes', 'required', 'integer', 'between:0,65535'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'title' => ['required', 'string', 'max:200'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
        if ($partial) {
            foreach ($rules as &$rule) {
                if (! in_array('sometimes', $rule, true)) {
                    array_unshift($rule, 'sometimes');
                }
            }
            unset($rule);
        }

        return $rules;
    }

    public function rules(): array
    {
        return self::fieldRules($this->route('sowingMethod'), $this instanceof UpdateSowingMethodRequest);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

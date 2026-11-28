<?php

namespace App\Http\Requests\Admin;

use App\Services\CropFieldLayoutOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropFieldLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-field-layouts') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public static function fieldRules(bool $partial = false): array
    {
        $rules = [
            'crop_scope' => ['required', Rule::in(CropFieldLayoutOptions::CROP_SCOPES)],
            'crop_ids' => ['required_if:crop_scope,specific', 'array', 'list', 'max:1000'],
            'crop_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sowing_method_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'farming_type' => ['sometimes', 'nullable', Rule::in(array_keys(CropFieldLayoutOptions::FARMING_TYPES))],
            'growing_system' => ['sometimes', 'nullable', Rule::in(array_keys(CropFieldLayoutOptions::GROWING_SYSTEMS))],
            'layout_type' => ['required', Rule::in(array_keys(CropFieldLayoutOptions::layouts()))],
            'sort_order' => ['required', 'integer', 'between:0,65535'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'draft_revision' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
        if ($partial) {
            foreach ($rules as $key => &$rule) {
                if (! str_contains($key, '.*') && ! in_array('sometimes', $rule, true)) {
                    array_unshift($rule, 'sometimes');
                }
            }
            unset($rule);
        }

        return $rules;
    }

    public function rules(): array
    {
        return self::fieldRules($this instanceof UpdateCropFieldLayoutRequest);
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [fn (Validator $validator) => self::rejectUnexpected($this, $validator, array_keys($this->rules()))];
    }

    /** @param list<string> $allowed */
    public static function rejectUnexpected(FormRequest $request, Validator $validator, array $allowed): void
    {
        foreach (array_diff(array_keys($request->all()), [...$allowed, '_method']) as $field) {
            $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
        }
    }
}

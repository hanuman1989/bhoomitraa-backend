<?php

namespace App\Http\Requests\Admin;

use App\Models\Fertilizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFertilizerStepOneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fertilizers') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public static function fieldRules(?Fertilizer $fertilizer = null, bool $partial = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('fertilizers', 'slug')->ignore($fertilizer?->id)],
            'fertilizer_code' => ['nullable', 'string', 'max:100', Rule::unique('fertilizers', 'fertilizer_code')->ignore($fertilizer?->id)],
            'fertilizer_type' => ['required', Rule::in(Fertilizer::TYPES)], 'form' => ['required', Rule::in(Fertilizer::FORMS)],
            'brand_name' => ['nullable', 'string', 'max:150'], 'manufacturer_name' => ['nullable', 'string', 'max:200'],
            'grade' => ['nullable', 'string', 'max:50'], 'default_unit_code' => ['required', Rule::in(Fertilizer::UNIT_CODES)],
            'reference_pack_quantity' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'reference_pack_unit_code' => ['nullable', Rule::in(Fertilizer::UNIT_CODES)],
            'application_method' => ['nullable', Rule::in(Fertilizer::APPLICATION_METHODS)],
        ];
        if ($partial) {
            foreach ($rules as &$rule) {
                array_unshift($rule, 'sometimes');
            }
            unset($rule);
        }

        return $rules;
    }

    public function rules(): array
    {
        return self::fieldRules($this->route('fertilizer'), $this instanceof UpdateFertilizerStepOneRequest);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $fertilizer = $this->route('fertilizer') instanceof Fertilizer ? clone $this->route('fertilizer') : new Fertilizer;
            $fertilizer->fill(Arr::only($validator->getData(), $fertilizer->getFillable()));
            foreach ($fertilizer->basicErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\Seed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSeedStepOneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-seeds') ?? false;
    }

    /** Shared with finalization to validate saved values using the same rules. @return array<string, array<mixed>> */
    public static function fieldRules(?Seed $seed = null, bool $partial = false): array
    {
        $rules = [
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['required', 'string', 'max:220', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', Rule::unique('seeds', 'slug')->ignore($seed?->id)],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('seeds', 'sku')->ignore($seed?->id)],
            'brand_name' => ['nullable', 'string', 'max:150'], 'producer_name' => ['nullable', 'string', 'max:200'],
            'material_type' => ['required', Rule::in(Seed::MATERIAL_TYPES)],
            'seed_type' => ['nullable', Rule::in(Seed::SEED_TYPES)],
            'propagation_method' => ['nullable', Rule::in(Seed::PROPAGATION_METHODS)],
            'seed_class' => ['nullable', Rule::in(Seed::SEED_CLASSES)],
            'treatment_status' => ['required', Rule::in(Seed::TREATMENT_STATUSES)],
            'default_unit_code' => ['required', Rule::in(Seed::UNIT_CODES)],
            'reference_pack_quantity' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'reference_pack_unit_code' => ['nullable', Rule::in(Seed::UNIT_CODES)],
            'seeds_per_gram' => ['nullable', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'germination_reference_pct' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'purity_reference_pct' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'is_f1_hybrid' => ['nullable', 'boolean'], 'is_organic' => ['nullable', 'boolean'], 'is_pelleted' => ['nullable', 'boolean'],
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
        return self::fieldRules($this->route('seed'), $this instanceof UpdateSeedStepOneRequest);
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
            $seed = $this->route('seed') instanceof Seed ? clone $this->route('seed') : new Seed;
            $seed->fill(Arr::only($validator->getData(), $seed->getFillable()));
            foreach ($seed->basicErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

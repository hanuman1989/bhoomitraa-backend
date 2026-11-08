<?php

namespace App\Http\Requests\Admin;

use App\Models\CropSeedRate;
use App\Models\Seed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropSeedRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-seeds') ?? false)
            && (! in_array($this->input('is_active'), [true, 1, '1'], true) || $this->user()->can('review-knowledge-sources'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'seed_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('seeds', 'id')],
            'season_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'material_type' => ['required', Rule::in(Seed::MATERIAL_TYPES)],
            'sowing_method' => ['sometimes', 'nullable', Rule::in(CropSeedRate::SOWING_METHODS)],
            'irrigation_condition' => ['sometimes', 'nullable', Rule::in(CropSeedRate::IRRIGATION_CONDITIONS)],
            'rate_basis' => ['required', Rule::in(CropSeedRate::RATE_BASES)],
            'rate_min' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'rate_recommended' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'rate_max' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999999.9999'],
            'rate_unit_code' => ['required', Rule::in(Seed::UNIT_CODES)],
            'area_unit_code' => ['required', Rule::in(CropSeedRate::AREA_UNIT_CODES)],
            'spacing_row_cm' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'spacing_plant_cm' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999.99'],
            'region_scope' => ['required', Rule::in(CropSeedRate::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('regions', 'id')->where('region_type', 'district')],
            'source_id' => ['required', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = $this->route('cropSeedRate');
            $rate = $existing instanceof CropSeedRate ? $existing : new CropSeedRate;
            $regionIds = $rate->resolveRegionIds($validator->getData());
            $candidate = clone $rate;
            $candidate->fill(Arr::only($validator->getData(), $candidate->getFillable()));
            foreach ($candidate->validationErrors($regionIds) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['region_ids.*.exists' => 'Select an existing district.', 'region_ids.*.distinct' => 'Each district may only be selected once.'];
    }
}

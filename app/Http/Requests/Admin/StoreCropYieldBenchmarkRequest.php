<?php

namespace App\Http\Requests\Admin;

use App\Models\CropYieldBenchmark;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropYieldBenchmarkRequest extends FormRequest
{
    public function authorize(): bool
    {
        $benchmark = $this->route('cropYieldBenchmark');
        $active = $this->input('is_active', $benchmark instanceof CropYieldBenchmark ? $benchmark->is_active : true);

        return ($this->user()?->can('manage-crops') ?? false)
            && (! in_array($active, [true, 1, '1'], true) || $this->user()->can('review-knowledge-sources'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'season_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'region_scope' => ['required', Rule::in(CropYieldBenchmark::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('regions', 'id')->where('region_type', 'district')],
            'yield_min_kg_ha' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'yield_avg_kg_ha' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'yield_max_kg_ha' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999999.999'],
            'benchmark_type' => ['required', Rule::in(CropYieldBenchmark::BENCHMARK_TYPES)],
            'source_id' => ['required', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'reference_year' => ['sometimes', 'nullable', 'integer', 'digits:4', 'min:1000', 'max:'.min(9999, now()->year)],
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
            $existing = $this->route('cropYieldBenchmark');
            $benchmark = $existing instanceof CropYieldBenchmark ? $existing : new CropYieldBenchmark;
            $regionIds = $benchmark->resolveRegionIds($validator->getData());
            $candidate = clone $benchmark;
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

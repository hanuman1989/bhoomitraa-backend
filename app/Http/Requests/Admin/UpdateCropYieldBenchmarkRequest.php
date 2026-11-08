<?php

namespace App\Http\Requests\Admin;

use App\Models\CropYieldBenchmark;

class UpdateCropYieldBenchmarkRequest extends StoreCropYieldBenchmarkRequest
{
    public function authorize(): bool
    {
        $benchmark = $this->route('cropYieldBenchmark');

        return parent::authorize() && (! $benchmark instanceof CropYieldBenchmark || ! $benchmark->is_active || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['crop_id', 'region_scope', 'yield_avg_kg_ha', 'benchmark_type', 'source_id'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

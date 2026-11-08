<?php

namespace App\Http\Requests\Admin;

use App\Models\CropSeedRate;

class UpdateCropSeedRateRequest extends StoreCropSeedRateRequest
{
    public function authorize(): bool
    {
        $rate = $this->route('cropSeedRate');

        return parent::authorize() && (! $rate instanceof CropSeedRate || ! $rate->is_active || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['crop_id', 'material_type', 'rate_basis', 'rate_recommended', 'rate_unit_code', 'area_unit_code', 'region_scope', 'source_id'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

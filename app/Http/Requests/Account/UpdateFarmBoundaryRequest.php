<?php

namespace App\Http\Requests\Account;

use Illuminate\Support\Arr;

class UpdateFarmBoundaryRequest extends StoreFarmRequest
{
    public function rules(): array
    {
        $rules = Arr::only(parent::rules(), ['boundary', 'boundary_source', 'gps_accuracy_m']);
        $rules['boundary'] = ['present', 'nullable', 'array:type,coordinates'];

        return $rules;
    }
}

<?php

namespace App\Http\Requests\Account;

use App\Models\Farm;
use Illuminate\Support\Arr;

class UpdateFarmRequest extends StoreFarmRequest
{
    public function rules(): array
    {
        $rules = Arr::only(parent::rules(), Farm::BASIC_FIELDS);
        foreach (['name', 'area', 'area_unit', 'state_id', 'district_id', 'village'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

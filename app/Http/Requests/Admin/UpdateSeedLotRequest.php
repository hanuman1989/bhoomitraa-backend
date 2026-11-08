<?php

namespace App\Http\Requests\Admin;

class UpdateSeedLotRequest extends StoreSeedLotRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['seed_id', 'lot_number'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

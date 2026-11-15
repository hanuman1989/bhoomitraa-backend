<?php

namespace App\Http\Requests\Account;

use Illuminate\Validation\Rule;

class UpdateFarmDetailsRequest extends StoreFarmRequest
{
    public function rules(): array
    {
        $rules = ['notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
        foreach (['khasra_number', 'khata_number', 'patwari_halka_number'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:100'];
        }
        foreach (config('farms.classifications') as $field => $values) {
            $rules[$field] = ['sometimes', 'nullable', 'string', Rule::in($values)];
        }

        return $rules;
    }

    public function after(): array
    {
        return [];
    }
}

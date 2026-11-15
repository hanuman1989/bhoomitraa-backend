<?php

namespace App\Http\Requests\Account;

class UpdateFarmStatusRequest extends StoreFarmRequest
{
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function after(): array
    {
        return [];
    }
}

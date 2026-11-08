<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }
}

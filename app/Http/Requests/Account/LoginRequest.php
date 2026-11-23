<?php

namespace App\Http\Requests\Account;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('login'))) {
            $this->merge(['login' => Str::lower(trim($this->input('login')))]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'login' => ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
                if (! filter_var($value, FILTER_VALIDATE_EMAIL) && ! preg_match('/\A[6-9][0-9]{9}\z/', $value)) {
                    $fail('The login field must be a valid email address or a 10-digit Indian mobile number.');
                }
            }],
            'password' => ['required', 'string', 'max:255'],
            'device_name' => ['sometimes', 'required', 'string', 'max:255'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}

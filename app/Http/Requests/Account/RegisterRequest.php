<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::squish($this->input($field));
            }
        }
        if (is_string($this->input('mobile'))) {
            $normalized['mobile'] = trim($this->input('mobile'));
        }
        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email'))) ?: null;
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'string', 'max:30', 'regex:/\\A[6-9][0-9]{9}\\z/', Rule::unique('users', 'mobile')],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'password_confirmation' => ['required', 'string', 'max:255'],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }
}

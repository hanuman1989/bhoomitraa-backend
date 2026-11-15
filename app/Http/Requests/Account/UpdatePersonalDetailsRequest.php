<?php

namespace App\Http\Requests\Account;

use App\Helpers\AreaHelper;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdatePersonalDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::squish($this->input($field));
            }
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'preferred_language' => ['required', 'string', Rule::in(array_keys(config('languages.supported')))],
            'preferred_units' => ['required', 'string', Rule::in(AreaHelper::supportedUnits())],
        ];
    }
}

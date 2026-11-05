<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-admin-users') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('admin_users')->ignore($this->route('admin_user'))],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'max:255', 'confirmed'],
            'status' => ['sometimes', 'boolean'],
            'is_super_user' => ['sometimes', 'boolean'],
        ];
    }
}

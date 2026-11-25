<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFarmCropActivityStatusRequest extends FormRequest
{
    /** Ownership is enforced by the controller's owner-scoped lookup (404 for other farmers). */
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    public function rules(): array
    {
        return ['action' => ['required', 'string', Rule::in(['complete', 'reopen'])]];
    }
}

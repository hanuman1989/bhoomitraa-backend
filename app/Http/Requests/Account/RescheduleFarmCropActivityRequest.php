<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RescheduleFarmCropActivityRequest extends FormRequest
{
    /** Ownership is enforced by the controller's owner-scoped lookup (404 for other farmers). */
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    /** Either new dates, or reset: true to go back to the planned dates. */
    public function rules(): array
    {
        return [
            'reset' => ['sometimes', 'boolean'],
            'start_date' => ['exclude_if:reset,true', 'required', 'date_format:Y-m-d'],
            'end_date' => ['exclude_if:reset,true', 'required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    public function wantsReset(): bool
    {
        return $this->boolean('reset');
    }
}

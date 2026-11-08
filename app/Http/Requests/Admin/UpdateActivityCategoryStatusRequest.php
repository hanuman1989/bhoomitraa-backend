<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateActivityCategoryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-activity-categories') ?? false)
            && (! $this->route('activityCategory')?->is_system || ($this->user()?->can('manage-system-activity-categories') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['is_active', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

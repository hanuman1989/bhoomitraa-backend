<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCropYieldBenchmarkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && (! in_array($this->input('is_active'), [true, 1, '1'], true) || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['is_active', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

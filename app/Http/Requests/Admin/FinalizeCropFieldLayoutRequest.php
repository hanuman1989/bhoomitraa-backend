<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FinalizeCropFieldLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-field-layouts') ?? false;
    }

    public function rules(): array
    {
        return ['draft_revision' => ['sometimes', 'required', 'integer', 'min:0']];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => StoreCropFieldLayoutRequest::rejectUnexpected($this, $validator, array_keys($this->rules()))];
    }
}

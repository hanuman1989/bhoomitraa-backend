<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCropCategoryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-categories') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}

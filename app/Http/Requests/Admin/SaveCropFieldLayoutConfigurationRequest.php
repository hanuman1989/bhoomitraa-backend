<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use stdClass;

class SaveCropFieldLayoutConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crop-field-layouts') ?? false;
    }

    public function rules(): array
    {
        return [
            'configuration' => ['present', 'array'],
            'reference_note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'draft_revision' => ['sometimes', 'required', 'integer', 'min:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            StoreCropFieldLayoutRequest::rejectUnexpected($this, $validator, array_keys($this->rules()));
            if ($this->isJson() && ! ((json_decode($this->getContent())->configuration ?? null) instanceof stdClass)) {
                $validator->errors()->add('configuration', 'The configuration must be a JSON object.');
            }
        }];
    }
}

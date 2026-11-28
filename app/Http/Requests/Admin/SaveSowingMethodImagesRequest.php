<?php

namespace App\Http\Requests\Admin;

use App\Models\SowingMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSowingMethodImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-sowing-methods') ?? false;
    }

    public function rules(): array
    {
        return [
            'images' => ['sometimes', 'array', 'max:'.SowingMethod::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'retain_image_ids' => ['sometimes', 'array', 'list', 'max:'.SowingMethod::MAX_IMAGES],
            'retain_image_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'remove_image_ids' => ['sometimes', 'array', 'list', 'max:'.SowingMethod::MAX_IMAGES],
            'remove_image_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'image_order' => ['sometimes', 'array', 'list', 'max:'.SowingMethod::MAX_IMAGES],
            'image_order.*' => ['required', 'string', 'regex:/^(?:id:[1-9][0-9]*|new:[a-zA-Z0-9_-]{1,50})$/D', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            $files = $this->file('images', []);
            if (! is_array($files)) {
                return;
            }
            foreach (array_keys($files) as $key) {
                if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', (string) $key)) {
                    $validator->errors()->add('images', 'Upload keys must contain only letters, numbers, hyphens and underscores.');
                }
            }
        }];
    }
}

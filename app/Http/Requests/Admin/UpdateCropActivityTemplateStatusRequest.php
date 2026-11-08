<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCropActivityTemplateStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && (! $this->boolean('is_active') || ($this->user()?->can('review-knowledge-sources') ?? false));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
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
            if ($validator->errors()->isNotEmpty() || ! $this->boolean('is_active')) {
                return;
            }
            $template = $this->route('cropActivityTemplate');
            if (! $template instanceof CropActivityTemplate) {
                return;
            }
            $candidate = clone $template;
            $candidate->is_active = true;
            foreach ($candidate->validationErrors($candidate->resolveRegionIds([])) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

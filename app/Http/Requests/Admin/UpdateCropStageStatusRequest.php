<?php

namespace App\Http\Requests\Admin;

use App\Models\CropStage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCropStageStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['is_active', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $stage = $this->route('cropStage');
            if (! $stage instanceof CropStage) {
                return;
            }

            $candidate = clone $stage;
            $candidate->is_active = $this->boolean('is_active');
            foreach ($candidate->activationErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

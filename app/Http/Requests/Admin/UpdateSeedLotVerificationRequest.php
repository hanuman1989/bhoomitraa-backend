<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSeedLotVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-seeds') ?? false) && $this->user()->can('review-knowledge-sources');
    }

    public function rules(): array
    {
        return ['verification_status' => ['required', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)]];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['verification_status', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

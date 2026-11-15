<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UpdateLocationDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('village'))) {
            $normalized['village'] = Str::squish($this->input('village'));
        }
        foreach (['address', 'pincode'] as $field) {
            if (is_string($this->input($field))) {
                $value = Str::trim($this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'state_id' => ['bail', 'required', 'integer', 'min:1'],
            'district_id' => ['bail', 'required', 'integer', 'min:1'],
            'village' => ['required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'pincode' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]{5}\z/'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $profile = $this->user('user')->farmerProfile()->firstOrNew();
            $profile->fill($validator->validated());
            foreach ($profile->locationErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

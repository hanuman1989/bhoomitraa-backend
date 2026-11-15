<?php

namespace App\Http\Requests\Account;

use App\Helpers\AreaHelper;
use App\Models\Farm;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class StoreFarmRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user('user') instanceof User) {
            return false;
        }
        if ($this->route('farm') !== null) {
            $this->user('user')->farms()->findOrFail($this->route('farm'));
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['name', 'village'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::squish($this->input($field));
            }
        }
        foreach (['description', 'ownership_type', 'address', ...Farm::DETAIL_FIELDS] as $field) {
            if (is_string($this->input($field))) {
                $value = Str::trim($this->input($field));
                $normalized[$field] = $value === '' ? null : $value;
            }
        }
        if ($this->isMethod('post')) {
            $normalized['idempotency_key'] = $this->header('Idempotency-Key');
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'ownership_type' => ['sometimes', 'nullable', 'string', Rule::in(Farm::OWNERSHIP_TYPES)],
            'area' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
            'area_unit' => ['required', 'string', Rule::in(AreaHelper::supportedUnits())],
            'state_id' => ['bail', 'required', 'integer', 'min:1'],
            'district_id' => ['bail', 'required', 'integer', 'min:1'],
            'tehsil_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'village' => ['required', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'boundary' => ['sometimes', 'nullable', 'array:type,coordinates'],
            'boundary_source' => ['sometimes', 'nullable', 'string', Rule::in(Farm::BOUNDARY_SOURCES)],
            'gps_accuracy_m' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $input = $validator->validated();
            $candidate = $this->route('farm') !== null
                ? $this->user('user')->farms()->findOrFail($this->route('farm'))
                : new Farm;
            $candidate->fill(Arr::only($input, Farm::BASIC_FIELDS));
            foreach ($candidate->locationErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
            if (! array_key_exists('boundary', $input) && ! $this->isMethod('post')) {
                return;
            }
            $boundary = $input['boundary'] ?? null;
            $source = $input['boundary_source'] ?? null;
            $accuracy = $input['gps_accuracy_m'] ?? null;
            if ($boundary !== null) {
                if ($source === null) {
                    $validator->errors()->add('boundary_source', 'Select manual or gps when supplying a boundary.');
                }
                try {
                    Farm::normalizeBoundary($boundary);
                } catch (ValidationException $exception) {
                    foreach ($exception->errors()['boundary'] as $message) {
                        $validator->errors()->add('boundary', $message);
                    }
                }
            } elseif ($source !== null) {
                $validator->errors()->add('boundary_source', 'A boundary source requires a boundary.');
            }
            if ($accuracy !== null && ($boundary === null || $source !== 'gps')) {
                $validator->errors()->add('gps_accuracy_m', 'GPS accuracy may only be supplied with a GPS boundary.');
            }
        }];
    }

    public function messages(): array
    {
        return ['idempotency_key.required' => 'Provide an Idempotency-Key header containing a UUID.',
            'idempotency_key.uuid' => 'The Idempotency-Key header must contain a valid UUID.'];
    }
}

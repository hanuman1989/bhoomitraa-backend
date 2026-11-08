<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeSource;
use App\Models\Seed;
use App\Models\SeedLot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSeedLotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-seeds') ?? false)
            && (! in_array($this->input('verification_status'), ['verified', 'rejected', 'outdated'], true) || $this->user()->can('review-knowledge-sources'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $lot = $this->route('seedLot');

        return [
            'seed_id' => ['required', 'integer', 'min:1', Rule::exists('seeds', 'id')],
            'lot_number' => ['required', 'string', 'max:100', Rule::unique('seed_lots', 'lot_number')->where('seed_id', $this->integer('seed_id', $lot?->seed_id ?? 0))->ignore($lot?->id)],
            'seed_class' => ['nullable', Rule::in(Seed::SEED_CLASSES)],
            'seed_treatment_status' => ['nullable', Rule::in(Seed::TREATMENT_STATUSES)],
            'production_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'test_date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date', 'date_format:Y-m-d'],
            'germination_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'physical_purity_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'genetic_purity_percentage' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'certification_number' => ['nullable', 'string', 'max:100'],
            'certification_body' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'verification_status' => ['sometimes', 'required', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'report_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $lot = $this->route('seedLot') instanceof SeedLot ? clone $this->route('seedLot') : new SeedLot;
            $lot->fill(Arr::only($validator->getData(), $lot->getFillable()));
            foreach ($lot->validationErrors() as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropVarietyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $locales = implode(',', array_keys(config('languages.supported')));

        return [
            'crop_id' => ['required', 'integer', Rule::exists('crops', 'id')],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('crop_varieties', 'slug')->where('crop_id', $this->input('crop_id'))],
            'variety_code' => ['sometimes', 'nullable', 'string', 'max:50',
                Rule::unique('crop_varieties', 'variety_code')->where('crop_id', $this->input('crop_id'))],
            'maturity_days_min' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'maturity_days_max' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('knowledge_sources', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'translations' => ['required', 'array:'.$locales],
            'translations.*' => ['required', 'array:name,description,region_notes'],
            'translations.*.name' => ['required', 'string', 'max:255'],
            'translations.*.description' => ['sometimes', 'nullable', 'string'],
            'translations.*.region_notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'translations.en' => ['required', 'array:name,description,region_notes'],
            'translations.en.name' => ['required', 'string', 'max:255'],
            'translations.hi' => ['required', 'array:name,description,region_notes'],
            'translations.hi.name' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = [...(new CropVariety)->getFillable(), 'translations', '_method'];
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $minimum = $this->input('maturity_days_min');
            $maximum = $this->input('maturity_days_max');
            if ($minimum !== null && $maximum !== null && (int) $minimum > (int) $maximum) {
                $validator->errors()->add('maturity_days_max', 'The maximum maturity days must be greater than or equal to the minimum maturity days.');
            }

            if (! $this->boolean('is_active')) {
                return;
            }

            $crop = Crop::query()->find($this->integer('crop_id'));
            if ($crop === null || ! $crop->is_active) {
                $validator->errors()->add('crop_id', 'An active variety requires an active crop.');
            }

            if ($minimum === null && $maximum === null) {
                return;
            }

            $source = KnowledgeSource::query()->find($this->integer('source_id'));
            if ($source === null || ! $source->is_active || $source->verification_status !== 'verified') {
                $validator->errors()->add('source_id', 'An active, verified knowledge source is required to publish maturity information.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.name.required' => 'The English variety name is required.',
            'translations.hi.name.required' => 'The Hindi variety name is required.',
            'slug.regex' => 'The slug must contain lowercase letters, numbers, and single hyphens between words.',
        ];
    }
}

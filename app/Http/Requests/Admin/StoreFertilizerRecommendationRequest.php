<?php

namespace App\Http\Requests\Admin;

use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFertilizerRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recommendation = $this->route('fertilizerRecommendation');
        $active = $this->input('is_active', $recommendation instanceof FertilizerRecommendation ? $recommendation->is_active : true);

        return ($this->user()?->can('manage-crops') ?? false)
            && (! in_array($active, [true, 1, '1'], true) || $this->user()->can('review-knowledge-sources'));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')],
            'season_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('seasons', 'id')],
            'region_scope' => ['required', Rule::in(FertilizerRecommendation::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('regions', 'id')->where('region_type', 'district')],
            'recommendation_type' => ['required', Rule::in(FertilizerRecommendation::RECOMMENDATION_TYPES)],
            'irrigation_condition' => ['sometimes', 'nullable', Rule::in(FertilizerRecommendation::IRRIGATION_CONDITIONS)],
            'soil_condition' => ['sometimes', 'nullable', Rule::in(FertilizerRecommendation::SOIL_CONDITIONS)],
            'application_method' => ['sometimes', 'nullable', Rule::in(FertilizerRecommendation::APPLICATION_METHODS)],
            'reference_code' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('fertilizer_recommendations', 'reference_code')->ignore($this->route('fertilizerRecommendation'))],
            'nutrients' => ['required', 'array', 'list', 'min:1', 'max:'.count(FertilizerRecommendationNutrient::CODES)],
            'nutrients.*' => ['required', 'array:nutrient_code,recommended_quantity,unit_code,area_unit_code'],
            'nutrients.*.nutrient_code' => ['required', 'string', Rule::in(FertilizerRecommendationNutrient::CODES), 'distinct'],
            'nutrients.*.recommended_quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'nutrients.*.unit_code' => ['required', Rule::in(FertilizerRecommendationNutrient::unitCodes())],
            'nutrients.*.area_unit_code' => ['required', Rule::in(FertilizerRecommendationNutrient::areaUnitCodes())],
            'basal_dose_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'top_dressing_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'general_advisory' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'source_id' => ['required', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'reference_year' => ['sometimes', 'nullable', 'integer', 'digits:4', 'min:1000', 'max:'.min(9999, now()->year)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'required', 'boolean'],
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
            $existing = $this->route('fertilizerRecommendation');
            $recommendation = $existing instanceof FertilizerRecommendation ? $existing : new FertilizerRecommendation;
            $regionIds = $recommendation->resolveRegionIds($validator->getData());
            $candidate = clone $recommendation;
            $candidate->fill(Arr::only($validator->getData(), $candidate->getFillable()));
            foreach ($candidate->validationErrors($regionIds) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    public function messages(): array
    {
        return ['region_ids.*.exists' => 'Select an existing district.', 'region_ids.*.distinct' => 'Each district may only be selected once.'];
    }
}

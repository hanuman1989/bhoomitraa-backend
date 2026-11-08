<?php

namespace App\Http\Requests\Admin;

use App\Models\FertilizerRecommendation;

class UpdateFertilizerRecommendationRequest extends StoreFertilizerRecommendationRequest
{
    public function authorize(): bool
    {
        $recommendation = $this->route('fertilizerRecommendation');

        return parent::authorize() && (! $recommendation instanceof FertilizerRecommendation || ! $recommendation->is_active || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['crop_id', 'region_scope', 'recommendation_type', 'source_id', 'nutrients'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

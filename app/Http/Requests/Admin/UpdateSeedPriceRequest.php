<?php

namespace App\Http\Requests\Admin;

use App\Models\SeedPrice;

class UpdateSeedPriceRequest extends StoreSeedPriceRequest
{
    public function authorize(): bool
    {
        $price = $this->route('seedPrice');

        return parent::authorize() && (! $price instanceof SeedPrice || ! $price->is_active || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['seed_id', 'price_type', 'package_quantity', 'package_unit_code', 'package_price', 'region_scope', 'effective_from'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }
}

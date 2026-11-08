<?php

namespace App\Http\Requests\Admin;

use App\Models\Seed;
use App\Models\SeedPrice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSeedPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-seeds') ?? false)
            && (! $this->boolean('is_active') || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        return [
            'seed_id' => ['required', 'integer', 'min:1', Rule::exists('seeds', 'id')],
            'seller_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'price_type' => ['required', Rule::in(SeedPrice::PRICE_TYPES)],
            'package_quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999.9999'],
            'package_unit_code' => ['required', Rule::in(Seed::UNIT_CODES)],
            'package_price' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:999999999999.9999'],
            'currency_code' => ['sometimes', 'required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/D', Rule::in(config('seed_prices.currencies'))],
            'region_scope' => ['required', Rule::in(SeedPrice::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('regions', 'id')->where('region_type', 'district')],
            'effective_from' => ['required', 'date', 'date_format:Y-m-d'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'date_format:Y-m-d'],
            'source_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('knowledge_sources', 'id')],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = $this->route('seedPrice');
            $price = $existing instanceof SeedPrice ? $existing : new SeedPrice(['currency_code' => config('seed_prices.default_currency')]);
            $input = $validator->getData();
            $regionIds = $price->resolveRegionIds($input);
            $candidate = clone $price;
            $candidate->fill(Arr::only($input, $candidate->getFillable()));
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

<?php

namespace App\Http\Requests\Account;

/**
 * Partial update: every field is optional and the category can't change. The merged
 * record is validated by IrrigationAssetService inside the update transaction.
 */
class UpdateIrrigationAssetRequest extends StoreIrrigationAssetRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        // Another farmer's or a deleted asset is not found (404) before any validation.
        $this->user('user')->irrigationAssets()->select('irrigation_assets.id')->findOrFail($this->route('id'));

        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'asset_type' => ['prohibited'],
            ...$this->detailRules(),
        ];
    }
}

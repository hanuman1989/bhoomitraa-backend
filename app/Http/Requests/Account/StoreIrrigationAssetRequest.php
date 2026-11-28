<?php

namespace App\Http\Requests\Account;

use App\Enums\IrrigationAssetStatus;
use App\Enums\IrrigationAssetType;
use App\Enums\IrrigationOwnershipType;
use App\Enums\IrrigationPipelineType;
use App\Enums\IrrigationSourceType;
use App\Enums\IrrigationWaterAvailability;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Field rules for a new irrigation asset. Rules that depend on the category or on the
 * whole record (depth support, route versus length, geometry shape) are checked by
 * IrrigationAssetService. user_id is never accepted: it is not a rule, so it is ignored.
 */
class StoreIrrigationAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('name'))) {
            $normalized['name'] = Str::trim($this->input('name'));
        }
        if (is_string($this->input('notes'))) {
            $notes = Str::trim($this->input('notes'));
            $normalized['notes'] = $notes === '' ? null : $notes;
        }
        $this->merge($normalized);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'asset_type' => ['required', Rule::enum(IrrigationAssetType::class)],
            ...$this->detailRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            'name.required' => __('irrigation.validation.name_required'),
            'name.string' => __('irrigation.validation.name_required'),
            'name.max' => __('irrigation.validation.name_max'),
            'asset_type.required' => __('irrigation.validation.asset_type_required'),
            'asset_type.prohibited' => __('irrigation.validation.category_locked'),
            'status.required' => __('irrigation.validation.status_required'),
            'notes.string' => __('irrigation.validation.notes_max'),
            'notes.max' => __('irrigation.validation.notes_max'),
            'geometry.array' => __('irrigation.validation.geometry_invalid'),
        ];
        foreach (['asset_type', 'source_type', 'pipeline_type', 'water_availability', 'ownership_type', 'status'] as $field) {
            $messages[$field.'.'.Enum::class] = __('irrigation.validation.option_invalid');
        }
        foreach (['length_meters', 'diameter_mm', 'depth_meters'] as $field) {
            $messages[$field.'.numeric'] = __('irrigation.validation.number_invalid');
            $messages[$field.'.decimal'] = __('irrigation.validation.number_invalid');
            $messages[$field.'.gt'] = __('irrigation.validation.number_positive');
            $messages[$field.'.max'] = __('irrigation.validation.number_too_large');
        }

        return $messages;
    }

    /**
     * Rules shared by create and update. Dimensions are positive, with at most 2 decimals,
     * and fit their DECIMAL columns.
     *
     * @return array<string, array<mixed>>
     */
    protected function detailRules(): array
    {
        $dimension = fn (string $maximum): array => ['sometimes', 'nullable', 'bail', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.$maximum];

        return [
            'source_type' => ['sometimes', 'nullable', Rule::enum(IrrigationSourceType::class)],
            'pipeline_type' => ['sometimes', 'nullable', Rule::enum(IrrigationPipelineType::class)],
            'geometry' => ['sometimes', 'nullable', 'array'],
            'length_meters' => $dimension('9999999999.99'),
            'diameter_mm' => $dimension('999999.99'),
            'depth_meters' => $dimension('99999999.99'),
            'water_availability' => ['sometimes', 'nullable', Rule::enum(IrrigationWaterAvailability::class)],
            'ownership_type' => ['sometimes', 'nullable', Rule::enum(IrrigationOwnershipType::class)],
            'status' => ['sometimes', 'required', Rule::enum(IrrigationAssetStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}

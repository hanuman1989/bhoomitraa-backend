<?php

namespace App\Http\Requests\Admin;

use App\Models\CropVariety;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewFarmerSmartTimelineRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')->where('is_active', true)],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1', Rule::exists('crop_varieties', 'id')->where('is_active', true)],
            'season_id' => ['required', 'integer', 'min:1', Rule::exists('seasons', 'id')->where('is_active', true)],
            'sowing_date' => ['required', 'date', 'date_format:Y-m-d'],
            'district_id' => ['required', 'integer', 'min:1', Rule::exists('regions', 'id')->where('region_type', 'district')->where('is_active', true)],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['crop_id', 'crop_variety_id', 'season_id', 'sowing_date', 'district_id']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty() || $this->input('crop_variety_id') === null) {
                return;
            }
            if (! CropVariety::query()->whereKey($this->input('crop_variety_id'))->where('crop_id', $this->input('crop_id'))->exists()) {
                $validator->errors()->add('crop_variety_id', 'The selected variety must belong to the selected crop.');
            }
        }];
    }
}

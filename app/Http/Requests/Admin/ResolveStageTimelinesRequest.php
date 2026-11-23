<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropVariety;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ResolveStageTimelinesRequest extends FormRequest
{
    public const MAX_STAGES = 100;

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
            'crop_id' => ['required', 'integer', 'min:1', Rule::exists('crops', 'id')],
            'crop_stage_ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_STAGES],
            'crop_stage_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'variety_scope' => ['required', 'string', Rule::in(CropActivityTemplate::VARIETY_SCOPES)],
            'crop_variety_ids' => ['exclude_unless:variety_scope,specific', 'required', 'array', 'list', 'min:1'],
            'crop_variety_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'season_scope' => ['required', 'string', Rule::in(CropActivityTemplate::SEASON_SCOPES)],
            'season_ids' => ['exclude_unless:season_scope,specific', 'required', 'array', 'list', 'min:1'],
            'season_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'region_scope' => ['required', 'string', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'region_ids' => ['exclude_unless:region_scope,specific', 'required', 'array', 'list', 'min:1'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys(array_filter($this->rules(), fn (string $key): bool => ! str_contains($key, '.'), ARRAY_FILTER_USE_KEY))) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $data = $validator->getData();
            foreach (['variety_scope' => 'crop_variety_ids', 'season_scope' => 'season_ids', 'region_scope' => 'region_ids'] as $scope => $field) {
                $data[$field] = $data[$scope] === 'specific' ? $data[$field] : [];
            }
            $cropId = (int) $data['crop_id'];
            $stages = CropStage::query()->whereIn('id', $data['crop_stage_ids'])->pluck('crop_id', 'id');
            foreach ($data['crop_stage_ids'] as $index => $id) {
                if ((int) ($stages[$id] ?? 0) !== $cropId) {
                    $validator->errors()->add('crop_stage_ids.'.$index, 'Select a growth stage belonging to the selected crop.');
                }
            }
            $varieties = CropVariety::query()->whereIn('id', $data['crop_variety_ids'] ?? [])->pluck('crop_id', 'id');
            foreach ($data['crop_variety_ids'] ?? [] as $index => $id) {
                if ((int) ($varieties[$id] ?? 0) !== $cropId) {
                    $validator->errors()->add('crop_variety_ids.'.$index, 'Select a variety belonging to the selected crop.');
                }
            }
            $seasons = Season::query()->whereIn('id', $data['season_ids'] ?? [])->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            foreach ($data['season_ids'] ?? [] as $index => $id) {
                if (! in_array((int) $id, $seasons, true)) {
                    $validator->errors()->add('season_ids.'.$index, 'Select an existing season.');
                }
            }
            $districts = Region::query()->whereIn('id', $data['region_ids'] ?? [])->where('region_type', 'district')
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
            foreach ($data['region_ids'] ?? [] as $index => $id) {
                if (! in_array((int) $id, $districts, true)) {
                    $validator->errors()->add('region_ids.'.$index, 'Select an existing district.');
                }
            }
        }];
    }
}

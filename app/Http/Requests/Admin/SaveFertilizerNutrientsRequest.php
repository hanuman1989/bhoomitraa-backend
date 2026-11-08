<?php

namespace App\Http\Requests\Admin;

use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveFertilizerNutrientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-fertilizers') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public static function fieldRules(): array
    {
        return [
            'nutrients' => ['present', 'array', 'list', 'max:'.(count(FertilizerNutrient::CODES) * count(FertilizerNutrient::CONTENT_UNITS))],
            'nutrients.*' => ['required', 'array:nutrient_code,content_value,content_unit_code'],
            'nutrients.*.nutrient_code' => ['required', Rule::in(FertilizerNutrient::CODES)],
            'nutrients.*.content_value' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999.9999'],
            'nutrients.*.content_unit_code' => ['required', Rule::in(FertilizerNutrient::CONTENT_UNITS)],
        ];
    }

    /**
     * Called only after native row validation succeeds, including during finalization.
     *
     * @param  list<array{nutrient_code: string, content_value: string|int|float, content_unit_code: string}>  $rows
     * @return array<string, list<string>>
     */
    public static function compositionErrors(Fertilizer $fertilizer, array $rows): array
    {
        $errors = [];
        $seen = [];
        if ($rows === [] && ! in_array($fertilizer->fertilizer_type, Fertilizer::OPTIONAL_COMPOSITION_TYPES, true)) {
            $errors['nutrients'] = ['Enter at least one explicit nutrient row for this fertilizer type.'];
        }
        foreach ($rows as $index => $row) {
            $key = $row['nutrient_code'].'|'.$row['content_unit_code'];
            if (isset($seen[$key])) {
                $errors['nutrients.'.$index.'.nutrient_code'] = ['This nutrient and content unit combination is duplicated.'];
            }
            $seen[$key] = true;
            if (in_array($row['content_unit_code'], ['percent_ww', 'percent_wv'], true) && BigDecimal::of($row['content_value'])->isGreaterThan(100)) {
                $errors['nutrients.'.$index.'.content_value'] = ['Percentage content must be between 0 and 100.'];
            }
        }

        return $errors;
    }

    public function rules(): array
    {
        return [...self::fieldRules(), 'composition_notes' => ['sometimes', 'nullable', 'string', 'max:65535']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['nutrients', 'composition_notes', '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach (self::compositionErrors($this->route('fertilizer'), $validator->getData()['nutrients']) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }
}

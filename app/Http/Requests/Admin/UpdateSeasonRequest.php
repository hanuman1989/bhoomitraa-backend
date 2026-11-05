<?php

namespace App\Http\Requests\Admin;

use App\Models\Season;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateSeasonRequest extends StoreSeasonRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['slug'] = ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('seasons', 'slug')->ignore($this->route('season'))];
        array_unshift($rules['translations'], 'sometimes');
        $rules['translations.*.name'] = ['sometimes', 'required', 'string', 'max:255'];

        foreach (Season::REQUIRED_LOCALES as $locale) {
            array_unshift($rules['translations.'.$locale], 'sometimes');
            array_unshift($rules['translations.'.$locale.'.name'], 'sometimes');
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $season = $this->route('season');
            if (! $season instanceof Season) {
                return;
            }

            $translations = $this->input('translations', []);
            if ($translations === []) {
                return;
            }

            $existingLocales = $season->translations()->pluck('locale')->all();
            foreach ($translations as $locale => $values) {
                if (! array_key_exists('name', $values) && ! in_array($locale, $existingLocales, true)) {
                    $validator->errors()->add('translations.'.$locale.'.name', 'A name is required when adding a season translation.');
                }
            }
        }];
    }
}

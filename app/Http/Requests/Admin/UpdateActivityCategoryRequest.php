<?php

namespace App\Http\Requests\Admin;

use App\Models\ActivityCategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateActivityCategoryRequest extends StoreActivityCategoryRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-activity-categories') ?? false)
            && (! $this->route('activityCategory')?->is_system || ($this->user()?->can('manage-system-activity-categories') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['is_system']);
        $rules['slug'] = ['sometimes', 'required', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            Rule::unique('activity_categories', 'slug')->ignore($this->route('activityCategory'))];
        array_unshift($rules['translations'], 'sometimes');
        $rules['translations.*.name'] = ['sometimes', 'required', 'string', 'max:150'];
        foreach (ActivityCategory::REQUIRED_LOCALES as $locale) {
            array_unshift($rules['translations.'.$locale], 'sometimes');
            array_unshift($rules['translations.'.$locale.'.name'], 'sometimes');
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if (array_key_exists('is_system', $this->all())) {
                $validator->errors()->add('is_system', 'The system category flag cannot be changed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $category = $this->route('activityCategory');
            if ($category->is_system && $this->has('slug') && $this->input('slug') !== $category->slug) {
                $validator->errors()->add('slug', 'A system category slug cannot be changed.');
            }
            $existingLocales = $category->translations()->pluck('locale')->all();
            foreach ($this->input('translations', []) as $locale => $values) {
                if (! array_key_exists('name', $values) && ! in_array($locale, $existingLocales, true)) {
                    $validator->errors()->add('translations.'.$locale.'.name', 'A name is required when adding an activity category translation.');
                }
            }
        }];
    }
}

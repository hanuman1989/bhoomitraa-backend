<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCropActivityTemplateRequest extends FormRequest
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
        $rules = [
            'crop_id' => ['required', 'integer', 'min:1'],
            'category_id' => ['required', 'integer', 'min:1'],
            'crop_stage_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variety_scope' => ['required', 'string', Rule::in(CropActivityTemplate::VARIETY_SCOPES)],
            'crop_variety_ids' => ['sometimes', 'array', 'list'],
            'crop_variety_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'season_scope' => ['required', 'string', Rule::in(CropActivityTemplate::SEASON_SCOPES)],
            'season_ids' => ['sometimes', 'array', 'list'],
            'season_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'schedule_type' => ['required', 'string', Rule::in(CropActivityTemplate::SCHEDULE_TYPES)],
            'schedule_basis' => ['sometimes', 'string', Rule::in(CropActivityTemplate::SCHEDULE_BASES)],
            'start_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'end_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'activity_type' => ['sometimes', 'string', Rule::in(CropActivityTemplate::ACTIVITY_TYPES)],
            'allow_status_update' => ['sometimes', 'boolean'],
            'allow_expenses' => ['sometimes', 'boolean'],
            'allow_labour' => ['sometimes', 'boolean'],
            'allow_notes' => ['sometimes', 'boolean'],
            'allow_attachments' => ['sometimes', 'boolean'],
            'allow_rescheduling' => ['sometimes', 'boolean'],
            'smart_actions' => ['sometimes', 'nullable', 'array', 'list'],
            'smart_actions.*' => ['required', 'string', 'distinct', Rule::in(config('crop_activity_templates.smart_actions', []))],
            'knowledge_source_ids' => ['sometimes', 'array', 'list'],
            'knowledge_source_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'region_scope' => ['required', 'string', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'region_ids' => ['sometimes', 'array', 'list'],
            'region_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(CropActivityTemplate::PRIORITIES)],
            'translations' => ['required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:title,description,instructions'],
            'translations.*.title' => ['required', 'string', 'max:200'],
            'translations.*.description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'translations.*.instructions' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
        foreach (CropActivityTemplate::REQUIRED_LOCALES as $locale) {
            $rules['translations.'.$locale] = ['required', 'array:title,description,instructions'];
            $rules['translations.'.$locale.'.title'] = ['required', 'string', 'max:200'];
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $metadata = array_diff((new CropActivityTemplate)->getFillable(), ['is_active']);
            foreach (array_diff(array_keys($this->all()), [
                ...$metadata, 'crop_variety_ids', 'season_ids', 'region_ids', 'knowledge_source_ids', 'translations', '_method',
            ]) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $existing = $this->route('cropActivityTemplate');
            $template = $existing instanceof CropActivityTemplate ? clone $existing : new CropActivityTemplate;
            $data = $validator->getData();
            if (! $template->exists) {
                $template->schedule_basis = $data['schedule_basis'] ?? 'fixed_offsets';
                $template->activity_type = $data['activity_type'] ?? 'task';
                $template->allow_status_update = $data['allow_status_update'] ?? true;
                $template->allow_expenses = $data['allow_expenses'] ?? false;
                $template->allow_labour = $data['allow_labour'] ?? false;
                $template->allow_notes = $data['allow_notes'] ?? true;
                $template->allow_attachments = $data['allow_attachments'] ?? false;
                $template->allow_rescheduling = $data['allow_rescheduling'] ?? false;
                $template->smart_actions = $data['smart_actions'] ?? [];
                $template->trigger_type = 'scheduled';
                $template->trigger_config = null;
            }
            $regionIds = $template->resolveRegionIds($data);
            $varietyIds = $template->resolveVarietyIds($data);
            $seasonIds = $template->resolveSeasonIds($data);
            $knowledgeSourceIds = $template->resolveKnowledgeSourceIds($data);
            $template->fill(Arr::only($data, $metadata));
            if (($data['schedule_basis'] ?? $template->schedule_basis) === 'growth_stage') {
                $template->start_offset_days = null;
                $template->end_offset_days = null;
            }
            foreach ($template->validationErrors(
                $regionIds,
                $data['translations'] ?? [],
                false,
                $varietyIds,
                $seasonIds,
                $knowledgeSourceIds,
            ) as $field => $messages) {
                foreach ($messages as $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'translations.en.title.required' => 'The English activity title is required.',
            'translations.hi.title.required' => 'The Hindi activity title is required.',
            'region_ids.*.distinct' => 'Each district may only be selected once.',
        ];
    }
}

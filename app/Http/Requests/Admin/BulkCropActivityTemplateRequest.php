<?php

namespace App\Http\Requests\Admin;

use App\Models\CropActivityTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkCropActivityTemplateRequest extends FormRequest
{
    public const MAX_IDS = 100;

    /** @var array<string, list<string>> Allowed data keys for each operation. */
    public const OPERATION_FIELDS = [
        'features' => ['allow_status_update', 'allow_expenses', 'allow_labour', 'allow_notes', 'allow_attachments', 'allow_rescheduling'],
        'smart_actions' => ['smart_actions', 'mode'],
        'priority' => ['priority'],
        'knowledge_sources' => ['knowledge_source_ids', 'mode'],
        'applicability' => ['variety_scope', 'crop_variety_ids', 'season_scope', 'season_ids', 'region_scope', 'region_ids'],
        'stage_schedule' => ['crop_stage_id', 'schedule_type', 'schedule_basis', 'start_offset_days', 'end_offset_days', 'display_order', 'timing_exception_reason'],
        'clear_schedule' => [],
        'activate' => [],
        'deactivate' => [],
        'delete' => [],
    ];

    /** @var list<string> */
    public const MODES = ['replace', 'add', 'remove'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-crops') ?? false)
            && ($this->input('operation') !== 'activate' || ($this->user()?->can('review-knowledge-sources') ?? false));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $schedule = [...CropActivityTemplate::SCHEDULE_BASES];

        return [
            'ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'operation' => ['required', 'string', Rule::in(array_keys(self::OPERATION_FIELDS))],
            'data' => ['sometimes', 'array'],
            'data.mode' => ['sometimes', 'string', Rule::in(self::MODES)],
            'data.allow_status_update' => ['sometimes', 'boolean'],
            'data.allow_expenses' => ['sometimes', 'boolean'],
            'data.allow_labour' => ['sometimes', 'boolean'],
            'data.allow_notes' => ['sometimes', 'boolean'],
            'data.allow_attachments' => ['sometimes', 'boolean'],
            'data.allow_rescheduling' => ['sometimes', 'boolean'],
            'data.smart_actions' => [Rule::requiredIf($this->input('operation') === 'smart_actions'), 'array', 'list'],
            'data.smart_actions.*' => ['required', 'string', 'distinct', Rule::in(config('crop_activity_templates.smart_actions', []))],
            'data.priority' => [Rule::requiredIf($this->input('operation') === 'priority'), 'string', Rule::in(CropActivityTemplate::PRIORITIES)],
            'data.knowledge_source_ids' => [Rule::requiredIf($this->input('operation') === 'knowledge_sources'), 'array', 'list'],
            'data.knowledge_source_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'data.variety_scope' => ['sometimes', 'string', Rule::in(CropActivityTemplate::VARIETY_SCOPES)],
            'data.crop_variety_ids' => ['sometimes', 'array', 'list'],
            'data.crop_variety_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'data.season_scope' => ['sometimes', 'string', Rule::in(CropActivityTemplate::SEASON_SCOPES)],
            'data.season_ids' => ['sometimes', 'array', 'list'],
            'data.season_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'data.region_scope' => ['sometimes', 'string', Rule::in(CropActivityTemplate::REGION_SCOPES)],
            'data.region_ids' => ['sometimes', 'array', 'list'],
            'data.region_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'data.crop_stage_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'data.schedule_type' => ['sometimes', 'nullable', 'string', Rule::in(CropActivityTemplate::SCHEDULE_TYPES)],
            'data.schedule_basis' => ['sometimes', 'string', Rule::in($schedule)],
            'data.start_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'data.end_offset_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_OFFSET_DAYS],
            'data.display_order' => ['sometimes', 'integer', 'min:0', 'max:'.CropActivityTemplate::MAX_DISPLAY_ORDER],
            'data.timing_exception_reason' => ['sometimes', 'nullable', 'string', 'max:'.CropActivityTemplate::MAX_TIMING_EXCEPTION_REASON_LENGTH],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['ids', 'operation', 'data']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            $allowed = self::OPERATION_FIELDS[$this->input('operation')] ?? null;
            $data = $this->input('data', []);
            if ($allowed === null || ! is_array($data)) {
                return;
            }
            foreach (array_diff(array_keys($data), $allowed) as $field) {
                $validator->errors()->add('data.'.$field, 'The data.'.$field.' field is not allowed for the '.$this->input('operation').' operation.');
            }
            if (in_array($this->input('operation'), ['features', 'applicability', 'stage_schedule'], true) && $data === []) {
                $validator->errors()->add('data', 'Provide at least one field to update.');
            }
        }];
    }
}

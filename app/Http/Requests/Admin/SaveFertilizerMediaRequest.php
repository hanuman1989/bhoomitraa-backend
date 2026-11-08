<?php

namespace App\Http\Requests\Admin;

use App\Models\Fertilizer;
use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveFertilizerMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('manage-fertilizers') ?? false)
            && (! in_array($this->input('verification_status'), ['verified', 'rejected', 'outdated'], true) || $this->user()->can('review-knowledge-sources'))
            && (! in_array($this->input('is_active'), [true, 1, '1'], true) || $this->user()->can('review-knowledge-sources'));
    }

    public function rules(): array
    {
        return [
            'source_id' => ['sometimes', 'nullable', 'integer', Rule::exists('knowledge_sources', 'id')],
            'verification_status' => ['required', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'is_active' => ['sometimes', 'boolean'],
            'images' => ['sometimes', 'array', 'max:'.Fertilizer::MAX_IMAGES],
            'images.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'retain_image_ids' => ['sometimes', 'array', 'list', 'max:'.Fertilizer::MAX_IMAGES],
            'retain_image_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'remove_image_ids' => ['sometimes', 'array', 'list', 'max:'.Fertilizer::MAX_IMAGES],
            'remove_image_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'image_order' => ['sometimes', 'array', 'list', 'max:'.Fertilizer::MAX_IMAGES],
            'image_order.*' => ['required', 'string', 'regex:/^(?:id:[1-9][0-9]*|new:[a-zA-Z0-9_-]{1,50})$/D', 'distinct'],
            'alt_texts' => ['sometimes', 'array', 'max:'.Fertilizer::MAX_IMAGES],
            'alt_texts.*' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_method']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
            $files = $this->file('images', []);
            if (! is_array($files)) {
                return;
            }
            foreach (array_keys($files) as $key) {
                if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/D', (string) $key)) {
                    $validator->errors()->add('images', 'Upload keys must contain only letters, numbers, hyphens and underscores.');
                }
            }
        }];
    }
}

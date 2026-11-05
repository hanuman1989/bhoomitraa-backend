<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateKnowledgeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-knowledge-sources') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'publisher' => ['sometimes', 'required', 'string', 'max:255'],
            'source_type' => ['sometimes', 'required', Rule::in(KnowledgeSource::SOURCE_TYPES)],
            'source_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'language' => ['sometimes', 'nullable', 'string', 'max:20', Rule::in(array_keys(config('languages.supported')))],
            'published_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'publication_year' => ['sometimes', 'nullable', 'integer', 'between:1000,'.(now()->year + 1)],
            'reference_identifier' => ['sometimes', 'nullable', 'string', 'max:255'],
            'identifier_type' => ['sometimes', 'nullable', 'string', 'max:30', Rule::in(KnowledgeSource::IDENTIFIER_TYPES)],
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'license_notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'translations' => ['sometimes', 'required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:title'],
            'translations.*.title' => ['required', 'string', 'max:500'],
        ];
    }
}

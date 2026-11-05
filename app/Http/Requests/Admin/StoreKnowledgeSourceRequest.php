<?php

namespace App\Http\Requests\Admin;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKnowledgeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-knowledge-sources') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:500'],
            'publisher' => ['required', 'string', 'max:255'],
            'source_type' => ['required', Rule::in(KnowledgeSource::SOURCE_TYPES)],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'language' => ['nullable', 'string', 'max:20', Rule::in(array_keys(config('languages.supported')))],
            'published_at' => ['nullable', 'date_format:Y-m-d'],
            'publication_year' => ['nullable', 'integer', 'between:1000,'.(now()->year + 1)],
            'reference_identifier' => ['nullable', 'string', 'max:255'],
            'identifier_type' => ['nullable', 'string', 'max:30', Rule::in(KnowledgeSource::IDENTIFIER_TYPES)],
            'description' => ['nullable', 'string', 'max:65535'],
            'license_notes' => ['nullable', 'string', 'max:65535'],
            'translations' => ['sometimes', 'required', 'array:'.implode(',', array_keys(config('languages.supported')))],
            'translations.*' => ['required', 'array:title'],
            'translations.*.title' => ['required', 'string', 'max:500'],
            'translations.'.config('languages.default') => ['required_with:translations', 'array:title'],
            'translations.'.config('languages.default').'.title' => ['required_with:translations', 'string', 'max:500'],
        ];
    }
}

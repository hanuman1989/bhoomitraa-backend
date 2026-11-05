<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewKnowledgeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review-knowledge-sources') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['verify', 'reject', 'outdate', 'reopen'])],
            'review_notes' => ['required_if:action,outdate', 'nullable', 'string', 'max:65535'],
            'rejection_reason' => ['required_if:action,reject', 'nullable', 'string', 'max:65535'],
            'next_review_at' => ['exclude_unless:action,verify', 'nullable', 'date', 'after:now'],
        ];
    }
}

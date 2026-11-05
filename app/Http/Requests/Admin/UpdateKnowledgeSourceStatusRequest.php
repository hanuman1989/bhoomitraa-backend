<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKnowledgeSourceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-knowledge-sources') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }
}

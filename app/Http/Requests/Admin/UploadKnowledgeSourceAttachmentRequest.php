<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class UploadKnowledgeSourceAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-knowledge-sources') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'attachments' => ['required', 'array', 'min:1', 'max:10'],
            'attachments.*' => ['required', 'file', File::types(['pdf', 'docx', 'xlsx'])->max('10mb'), 'extensions:pdf,docx,xlsx'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->file('attachments', []) as $index => $file) {
                if ($file instanceof UploadedFile && mb_strlen($file->getClientOriginalName()) > 255) {
                    $validator->errors()->add('attachments.'.$index, 'The attachment filename must not exceed 255 characters.');
                }
            }
        }];
    }
}

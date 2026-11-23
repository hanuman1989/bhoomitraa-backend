<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ImportCropActivityTemplatesRequest extends FormRequest
{
    public const MAX_TEMPLATES = 100;

    /** @var list<string> */
    public const MODES = ['preview', 'commit'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crops') ?? false;
    }

    /**
     * Row content is validated per template by the importer so previews can report every row.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(self::MODES)],
            'templates' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_TEMPLATES],
            'templates.*' => ['required', 'array'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), ['mode', 'templates']) as $field) {
                $validator->errors()->add($field, 'The '.$field.' field is not allowed.');
            }
        }];
    }
}

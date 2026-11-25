<?php

namespace App\Http\Requests\Account;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreFarmCropActivityNoteRequest extends FormRequest
{
    /** Ownership is enforced by the controller's owner-scoped lookup (404 for other farmers). */
    public function authorize(): bool
    {
        return $this->user('user') instanceof User;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note'))) {
            $this->merge(['note' => Str::trim($this->input('note'))]);
        }
    }

    /** Notes are plain text; the API returns them as data and the client escapes them on output. */
    public function rules(): array
    {
        $today = CarbonImmutable::now(config('crop_timeline.timezone'))->toDateString();

        return [
            'note' => ['required', 'string', 'max:1000'],
            'note_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
        ];
    }
}

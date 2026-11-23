<?php

namespace App\Services;

use App\Http\Requests\Admin\StoreCropActivityTemplateRequest;
use App\Models\CropActivityTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Validates and creates imported templates with exactly the rules of the create endpoint.
 * Imported templates are always created inactive; commits are all-or-nothing.
 */
class CropActivityTemplateImporter
{
    public function __construct(private CropActivityTemplateWriter $writer) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{index: int, status: string, errors: array<string, list<string>>}>
     */
    public function preview(array $rows): array
    {
        $results = [];
        foreach ($this->validateRows($rows) as $index => $outcome) {
            $results[] = [
                'index' => $index,
                'status' => $outcome['errors'] === [] ? 'valid' : 'invalid',
                'errors' => (object) $outcome['errors'],
            ];
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{index: int, status: string, id: int}>
     *
     * @throws ValidationException
     */
    public function commit(array $rows): array
    {
        $outcomes = $this->validateRows($rows);
        $errors = [];
        foreach ($outcomes as $index => $outcome) {
            foreach ($outcome['errors'] as $field => $messages) {
                $errors['templates.'.$index.'.'.$field] = $messages;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($outcomes): array {
            $results = [];
            foreach ($outcomes as $index => $outcome) {
                try {
                    $created = $this->writer->create($outcome['data']);
                } catch (ValidationException $exception) {
                    $prefixed = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $prefixed['templates.'.$index.'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($prefixed);
                }
                $results[] = [
                    'index' => $index,
                    'status' => $created['created'] ? 'created' : 'existing',
                    'id' => (int) $created['template']->id,
                ];
            }

            return $results;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{errors: array<string, list<string>>, data: array<string, mixed>}>
     */
    private function validateRows(array $rows): array
    {
        $request = new StoreCropActivityTemplateRequest;
        $rules = $request->rules();
        $allowed = StoreCropActivityTemplateRequest::allowedFields();
        $requestIds = [];
        $outcomes = [];
        foreach (array_values($rows) as $index => $row) {
            $errors = [];
            foreach (array_diff(array_keys($row), $allowed) as $field) {
                $errors[$field] = ['The '.$field.' field is not allowed.'];
            }
            $validator = Validator::make($row, $rules, $request->messages());
            $data = [];
            if ($validator->fails()) {
                $errors = [...$errors, ...$validator->errors()->toArray()];
            } else {
                $data = $validator->validated();
            }
            $requestId = $row['creation_request_id'] ?? null;
            if (is_string($requestId) && $requestId !== '') {
                if (in_array($requestId, $requestIds, true)) {
                    $errors['creation_request_id'][] = 'Each imported template must use a distinct creation request ID.';
                }
                $requestIds[] = $requestId;
            }
            if ($errors === [] && ! CropActivityTemplate::query()->where('creation_request_id', $requestId ?? '')->exists()) {
                $errors = $this->writer->candidateErrors(null, $row);
            }
            $outcomes[$index] = ['errors' => $errors, 'data' => $data];
        }

        return $outcomes;
    }
}

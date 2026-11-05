<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKnowledgeSourceRequest;
use App\Http\Requests\Admin\UpdateKnowledgeSourceRequest;
use App\Http\Requests\Admin\UpdateKnowledgeSourceStatusRequest;
use App\Http\Resources\Admin\KnowledgeSourceResource;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeSourceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'source_type' => ['sometimes', Rule::in(KnowledgeSource::SOURCE_TYPES)],
            'verification_status' => ['sometimes', Rule::in(KnowledgeSource::VERIFICATION_STATUSES)],
            'language' => ['sometimes', Rule::in(array_keys(config('languages.supported')))],
            'is_active' => ['sometimes', 'boolean'],
            'review_due' => ['sometimes', 'boolean'],
            'sort_by' => ['sometimes', Rule::in(['created_at', 'title', 'publisher', 'published_at', 'publication_year', 'next_review_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = KnowledgeSource::query()->with(['attachments', 'verifier:id,first_name,last_name']);
        foreach (['source_type', 'verification_status', 'language', 'is_active'] as $filter) {
            if (array_key_exists($filter, $filters)) {
                $query->where($filter, $filters[$filter]);
            }
        }
        if (array_key_exists('review_due', $filters)) {
            $query->where(function (Builder $query) use ($request): void {
                if ($request->boolean('review_due')) {
                    $query->where('next_review_at', '<=', now('UTC'));
                } else {
                    $query->whereNull('next_review_at')->orWhere('next_review_at', '>', now('UTC'));
                }
            });
        }
        $keyword = trim($filters['keyword'] ?? '');
        if ($keyword !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("title LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("publisher LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("reference_identifier LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $direction = $filters['sort_direction'] ?? 'desc';
        $sources = $query->orderBy($filters['sort_by'] ?? 'created_at', $direction)->orderBy('id', $direction)
            ->paginate($request->integer('per_page', 15))->appends($request->only(array_diff(array_keys($filters), ['page'])));

        return response()->json([
            'status' => true,
            'data' => KnowledgeSourceResource::collection($sources),
            'pagination' => [
                'current_page' => $sources->currentPage(),
                'last_page' => $sources->lastPage(),
                'per_page' => $sources->perPage(),
                'total' => $sources->total(),
                'from' => $sources->firstItem(),
                'to' => $sources->lastItem(),
                'next_page_url' => $sources->nextPageUrl(),
                'prev_page_url' => $sources->previousPageUrl(),
            ],
            'message' => 'Knowledge sources retrieved successfully.',
        ]);
    }

    public function store(StoreKnowledgeSourceRequest $request): JsonResponse
    {
        $source = DB::transaction(function () use ($request): KnowledgeSource {
            $source = new KnowledgeSource($request->safe()->except('translations'));
            $source->resetVerification();
            $source->created_by = $request->user()->getAuthIdentifier();
            $source->updated_by = $request->user()->getAuthIdentifier();
            $source->save();
            $source->saveTranslations($request->validated('translations', []));

            return $source->load(['translations', 'attachments', 'creator:id,first_name,last_name', 'updater:id,first_name,last_name']);
        });

        return response()->json([
            'status' => true,
            'data' => new KnowledgeSourceResource($source),
            'pagination' => null,
            'message' => 'Knowledge source created successfully.',
        ], 201);
    }

    public function show(KnowledgeSource $knowledgeSource): JsonResponse
    {
        $knowledgeSource->load(['translations', 'attachments', 'verifier:id,first_name,last_name', 'creator:id,first_name,last_name', 'updater:id,first_name,last_name']);

        return response()->json([
            'status' => true,
            'data' => new KnowledgeSourceResource($knowledgeSource),
            'pagination' => null,
            'message' => 'Knowledge source retrieved successfully.',
        ]);
    }

    public function update(UpdateKnowledgeSourceRequest $request, KnowledgeSource $knowledgeSource): JsonResponse
    {
        $source = DB::transaction(function () use ($request, $knowledgeSource): KnowledgeSource {
            $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
            $source->fill($request->safe()->except('translations'));
            $translationsChanged = $source->saveTranslations($request->validated('translations', []));
            if ($source->isDirty([
                'title', 'publisher', 'source_type', 'source_url', 'language', 'published_at',
                'publication_year', 'reference_identifier', 'identifier_type', 'description',
            ]) || $translationsChanged) {
                $source->resetVerification();
            }
            $source->updated_by = $request->user()->getAuthIdentifier();
            $source->save();

            return $source->load(['translations', 'attachments', 'verifier:id,first_name,last_name', 'creator:id,first_name,last_name', 'updater:id,first_name,last_name']);
        });

        return response()->json([
            'status' => true,
            'data' => new KnowledgeSourceResource($source),
            'pagination' => null,
            'message' => 'Knowledge source updated successfully.',
        ]);
    }

    public function updateStatus(UpdateKnowledgeSourceStatusRequest $request, KnowledgeSource $knowledgeSource): JsonResponse
    {
        $source = DB::transaction(function () use ($request, $knowledgeSource): KnowledgeSource {
            $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
            if ($request->boolean('is_active') && ($source->verification_status !== 'verified' || ! $source->hasUsableReference())) {
                throw ValidationException::withMessages(['is_active' => ['Only verified sources with a usable reference can be activated.']]);
            }
            $source->is_active = $request->boolean('is_active');
            $source->updated_by = $request->user()->getAuthIdentifier();
            $source->save();

            return $source->load('attachments');
        });

        return response()->json([
            'status' => true,
            'data' => new KnowledgeSourceResource($source),
            'pagination' => null,
            'message' => 'Knowledge source status updated successfully.',
        ]);
    }

    public function destroy(KnowledgeSource $knowledgeSource): JsonResponse
    {
        try {
            $deleted = DB::transaction(function () use ($knowledgeSource): bool {
                $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
                if ($source->is_active) {
                    return false;
                }

                /** Explicit Eloquent deletes dispatch file cleanup after commit; FK cascades cannot do this. */
                foreach ($source->attachments()->get() as $attachment) {
                    $attachment->delete();
                }
                $source->delete();

                return true;
            });
        } catch (QueryException $exception) {
            if (in_array($exception->errorInfo[1] ?? null, [1451, 787], true)
                || (($exception->errorInfo[1] ?? null) === 19 && str_contains($exception->errorInfo[2] ?? '', 'FOREIGN KEY constraint failed'))) {
                $deleted = false;
            } else {
                throw $exception;
            }
        }
        if (! $deleted) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'This source is active or referenced by other records. Deactivate it or retain it for historical use.',
            ], 409);
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Knowledge source deleted successfully.',
        ]);
    }
}

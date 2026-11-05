<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewKnowledgeSourceRequest;
use App\Http\Resources\Admin\KnowledgeSourceResource;
use App\Models\KnowledgeSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KnowledgeSourceReviewController extends Controller
{
    public function store(ReviewKnowledgeSourceRequest $request, KnowledgeSource $knowledgeSource): JsonResponse
    {
        $source = DB::transaction(function () use ($request, $knowledgeSource): KnowledgeSource {
            $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
            $action = $request->validated('action');
            if ($action === 'verify' && ! $source->hasUsableReference()) {
                throw ValidationException::withMessages(['action' => ['A usable source URL, reference identifier, or stored attachment is required before verification.']]);
            }
            if ($action === 'reopen') {
                $source->resetVerification();
                $source->review_notes = $request->validated('review_notes');
            } else {
                $source->verification_status = match ($action) {
                    'verify' => 'verified', 'reject' => 'rejected', 'outdate' => 'outdated',
                };
                $source->is_active = false;
                $source->verified_by = $request->user()->getAuthIdentifier();
                $source->verified_at = now('UTC');
                $source->last_checked_at = now('UTC');
                $source->next_review_at = $action === 'verify' && $request->validated('next_review_at') !== null
                    ? Carbon::parse($request->validated('next_review_at'))->utc() : null;
                $source->review_notes = $request->validated('review_notes');
                $source->rejection_reason = $action === 'reject' ? $request->validated('rejection_reason') : null;
            }
            $source->updated_by = $request->user()->getAuthIdentifier();
            $source->save();

            return $source->load(['attachments', 'verifier:id,first_name,last_name']);
        });

        return response()->json([
            'status' => true,
            'data' => new KnowledgeSourceResource($source),
            'pagination' => null,
            'message' => 'Knowledge source review saved successfully.',
        ]);
    }
}

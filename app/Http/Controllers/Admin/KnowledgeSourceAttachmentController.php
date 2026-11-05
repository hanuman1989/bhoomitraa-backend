<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadKnowledgeSourceAttachmentRequest;
use App\Http\Resources\Admin\KnowledgeSourceAttachmentResource;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSourceAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class KnowledgeSourceAttachmentController extends Controller
{
    public function store(UploadKnowledgeSourceAttachmentRequest $request, KnowledgeSource $knowledgeSource): JsonResponse
    {
        $disk = Storage::disk(config('filesystems.knowledge_sources_disk'));
        $paths = [];
        try {
            $attachments = DB::transaction(function () use ($request, $knowledgeSource, $disk, &$paths): array {
                $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
                $attachments = [];
                foreach ($request->file('attachments') as $file) {
                    $filename = Str::uuid().'.'.$file->guessExtension();
                    $directory = 'knowledge-sources/'.$source->id;
                    $path = $directory.'/'.$filename;
                    $paths[] = $path;
                    if ($disk->putFileAs($directory, $file, $filename, ['visibility' => 'private']) === false) {
                        throw new RuntimeException('Unable to store knowledge source attachment.');
                    }
                    $attachments[] = $source->attachments()->create([
                        'file_name' => $file->getClientOriginalName(),
                        'file_path' => $path,
                        'file_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
                $source->resetVerification();
                $source->updated_by = $request->user()->getAuthIdentifier();
                $source->save();

                return $attachments;
            });
        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                try {
                    if (! $disk->delete($path)) {
                        Log::warning('Failed upload cleanup requires attention.', ['knowledge_source_id' => $knowledgeSource->id]);
                    }
                } catch (Throwable $cleanupException) {
                    Log::warning('Failed upload cleanup requires attention.', [
                        'knowledge_source_id' => $knowledgeSource->id, 'exception_type' => $cleanupException::class,
                    ]);
                }
            }
            throw $exception;
        }

        return response()->json([
            'status' => true,
            'data' => KnowledgeSourceAttachmentResource::collection($attachments),
            'pagination' => null,
            'message' => 'Knowledge source attachments uploaded successfully.',
        ], 201);
    }

    public function download(KnowledgeSource $knowledgeSource, KnowledgeSourceAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->knowledge_source_id === $knowledgeSource->id, 404);
        $disk = Storage::disk(config('filesystems.knowledge_sources_disk'));
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->download($attachment->file_path, $attachment->file_name, [
            'Content-Type' => $attachment->file_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, KnowledgeSource $knowledgeSource, KnowledgeSourceAttachment $attachment): JsonResponse
    {
        $deleted = DB::transaction(function () use ($request, $knowledgeSource, $attachment): bool {
            $source = KnowledgeSource::query()->lockForUpdate()->findOrFail($knowledgeSource->id);
            $attachment = $source->attachments()->findOrFail($attachment->id);
            if ($source->verification_status === 'verified' && ! $source->hasUsableReference($attachment->id)) {
                return false;
            }
            $attachment->delete();
            $source->resetVerification();
            $source->updated_by = $request->user()->getAuthIdentifier();
            $source->save();

            return true;
        });
        if (! $deleted) {
            return response()->json([
                'status' => false,
                'data' => null,
                'pagination' => null,
                'message' => 'The last usable reference of a verified source cannot be removed. Reopen the source for review first.',
            ], 409);
        }

        return response()->json([
            'status' => true,
            'data' => null,
            'pagination' => null,
            'message' => 'Knowledge source attachment deleted successfully.',
        ]);
    }
}

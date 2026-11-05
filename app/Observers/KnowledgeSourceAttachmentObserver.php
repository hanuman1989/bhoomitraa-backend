<?php

namespace App\Observers;

use App\Models\KnowledgeSourceAttachment;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KnowledgeSourceAttachmentObserver implements ShouldHandleEventsAfterCommit
{
    public function deleted(KnowledgeSourceAttachment $attachment): void
    {
        try {
            if (! Storage::disk(config('filesystems.knowledge_sources_disk'))->delete($attachment->getRawOriginal('file_path'))) {
                Log::warning('Knowledge source attachment cleanup failed.', ['attachment_id' => $attachment->id]);
            }
        } catch (Throwable $exception) {
            Log::warning('Knowledge source attachment cleanup failed.', [
                'attachment_id' => $attachment->id,
                'exception_type' => $exception::class,
            ]);
        }
    }
}

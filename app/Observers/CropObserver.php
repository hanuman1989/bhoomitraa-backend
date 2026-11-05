<?php

namespace App\Observers;

use App\Models\Crop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CropObserver
{
    public function updated(Crop $crop): void
    {
        if ($crop->wasChanged('image')) {
            $this->deleteAfterCommit($crop, $crop->getRawOriginal('image'));
        }
    }

    public function deleted(Crop $crop): void
    {
        $this->deleteAfterCommit($crop, $crop->getRawOriginal('image'));
    }

    /** Capture the persisted old path before Eloquent synchronizes the model's originals. */
    private function deleteAfterCommit(Crop $crop, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $disk = config('filesystems.crop_images_disk');
        $connection = $crop->getConnectionName();
        $cropId = $crop->id;
        $crop->getConnection()->afterCommit(function () use ($disk, $path, $connection, $cropId): void {
            try {
                if (Crop::on($connection)->where('image', $path)->exists()) {
                    return;
                }
                if (! Storage::disk($disk)->delete($path)) {
                    Log::warning('Crop image cleanup failed.', ['crop_id' => $cropId]);
                }
            } catch (Throwable $exception) {
                Log::warning('Crop image cleanup failed.', ['crop_id' => $cropId, 'exception_type' => $exception::class]);
            }
        });
    }
}

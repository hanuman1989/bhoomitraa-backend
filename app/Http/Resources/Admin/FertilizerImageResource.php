<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class FertilizerImageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'image_url' => Storage::disk(config('filesystems.fertilizer_images_disk'))->url($this->image_path),
            'alt_text' => $this->alt_text, 'sort_order' => $this->sort_order];
    }
}

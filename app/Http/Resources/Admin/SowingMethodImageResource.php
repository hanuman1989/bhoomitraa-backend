<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class SowingMethodImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id,
            'image_url' => Storage::disk(config('filesystems.sowing_method_images_disk'))->url($this->image),
            'sort_order' => $this->sort_order];
    }
}

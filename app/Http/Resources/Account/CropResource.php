<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CropResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $translation = $this->resource->relationLoaded('translations') ? ($this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'))) : null;

        return [
            'id' => $this->id, ...$this->resource->only(['category_id', 'slug', 'scientific_name', 'crop_code', 'crop_color', 'is_recommendate']),
            'name' => $this->localized_name ?? $translation?->name,
            'description' => $translation?->description,
            'image' => $this->image === null ? null : Storage::disk(config('filesystems.crop_images_disk'))->url($this->image),
            'category' => $this->whenLoaded('category', fn () => new CropCategoryResource($this->category)),
            'available_varieties_count' => $this->whenCounted('varieties'),
        ];
    }
}

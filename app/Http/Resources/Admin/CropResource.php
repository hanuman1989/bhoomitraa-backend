<?php

namespace App\Http\Resources\Admin;

use App\Models\CropTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class CropResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('admin.*');

        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn (): ?string => $this->category === null ? null
                : ($this->category->getAttribute('localized_name') ?? $this->category->translatedName())),
            'slug' => $this->slug,
            'scientific_name' => $this->scientific_name,
            'crop_code' => $this->crop_code,
            'image_url' => $this->image === null ? null : Storage::disk(config('filesystems.crop_images_disk'))->url($this->image),
            'is_active' => $this->is_active,
            'ai_diagnosis_enabled' => $this->ai_diagnosis_enabled,
            'name' => array_key_exists('localized_name', $this->resource->getAttributes())
                ? $this->localized_name : $this->resource->translatedName(),
            'translations' => $this->when($admin && $this->resource->relationLoaded('translations'), fn (): object => (object) $this->translations
                ->mapWithKeys(fn (CropTranslation $translation): array => [$translation->locale => new CropTranslationResource($translation)])->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

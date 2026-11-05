<?php

namespace App\Http\Resources\Admin;

use App\Models\CropCategoryTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $admin = $request->routeIs('admin.*');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => array_key_exists('localized_name', $this->resource->getAttributes())
                ? $this->localized_name : $this->resource->translatedName(),
            'is_active' => $this->is_active,
            'translations' => $this->when($admin && $this->resource->relationLoaded('translations'), fn (): object => (object) $this->translations
                ->mapWithKeys(fn (CropCategoryTranslation $translation): array => [
                    $translation->locale => new CropCategoryTranslationResource($translation),
                ])->all()),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

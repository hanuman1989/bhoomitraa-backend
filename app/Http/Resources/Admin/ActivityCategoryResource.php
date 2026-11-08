<?php

namespace App\Http\Resources\Admin;

use App\Models\ActivityCategoryTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'name' => $this->resource->getAttribute('localized_name')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedName(), null),
            'translations' => $this->when(
                ! $request->routeIs('admin.activity-categories.index') && $this->resource->relationLoaded('translations'),
                fn (): object => (object) $this->translations->mapWithKeys(
                    fn (ActivityCategoryTranslation $translation): array => [$translation->locale => new ActivityCategoryTranslationResource($translation)],
                )->all(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

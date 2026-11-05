<?php

namespace App\Http\Resources\Admin;

use App\Models\SeasonTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->resource->getAttribute('localized_name')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedName(), null),
            'description' => $this->resource->hasAttribute('localized_description')
                ? $this->resource->getAttribute('localized_description')
                : $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedDescription(), null),
            'is_active' => $this->is_active,
            'translations' => $this->when(
                $request->routeIs('admin.*') && ! $request->routeIs('admin.seasons.index')
                    && $this->resource->relationLoaded('translations'),
                fn (): object => (object) $this->translations->mapWithKeys(
                    fn (SeasonTranslation $translation): array => [$translation->locale => new SeasonTranslationResource($translation)],
                )->all(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

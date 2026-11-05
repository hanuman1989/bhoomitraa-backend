<?php

namespace App\Http\Resources\Admin;

use App\Models\RegionTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RegionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'region_type' => $this->region_type,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
            'name' => $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedName(), null),
            'translations' => $this->when($request->routeIs('admin.*') && $this->resource->relationLoaded('translations'), fn (): object => (object) $this->translations
                ->mapWithKeys(fn (RegionTranslation $translation): array => [
                    $translation->locale => new RegionTranslationResource($translation),
                ])->all()),
            'parent' => $this->whenLoaded('parent', fn (): ?array => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'region_type' => $this->parent->region_type,
                'name' => $this->parent->relationLoaded('translations') ? $this->parent->translatedName() : null,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('d M Y h:i A'),
        ];
    }
}

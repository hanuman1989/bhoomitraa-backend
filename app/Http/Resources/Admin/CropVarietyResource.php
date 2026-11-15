<?php

namespace App\Http\Resources\Admin;

use App\Models\CropVarietyTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropVarietyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->getAttribute('localized_crop_name')
                ?? $this->crop?->translatedName()),
            'crop_slug' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->slug),
            'crop_color' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->crop_color),
            'slug' => $this->slug,
            'variety_code' => $this->variety_code,
            'maturity_days_min' => $this->maturity_days_min,
            'maturity_days_max' => $this->maturity_days_max,
            'knowledge_sources' => KnowledgeSourceResource::collection($this->whenLoaded('knowledgeSources')),
            'is_active' => $this->is_active,
            'name' => $this->resource->getAttribute('localized_name')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedName(), null),
            'translations' => $this->when(
                $request->routeIs('admin.*') && $this->resource->relationLoaded('translations'),
                fn (): object => (object) $this->translations->mapWithKeys(
                    fn (CropVarietyTranslation $translation): array => [$translation->locale => new CropVarietyTranslationResource($translation)],
                )->all(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

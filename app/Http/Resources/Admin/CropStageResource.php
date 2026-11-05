<?php

namespace App\Http\Resources\Admin;

use App\Models\CropStageTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropStageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName(config('languages.default'))),
            'code' => $this->code,
            'sequence' => $this->sequence,
            'is_active' => $this->is_active,
            'name' => $this->resource->getAttribute('localized_name')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translatedName(), null),
            'translations' => $this->when(
                $request->routeIs('admin.*') && ! $request->routeIs('admin.crop-stages.index')
                    && $this->resource->relationLoaded('translations'),
                fn (): object => (object) $this->translations->mapWithKeys(
                    fn (CropStageTranslation $translation): array => [$translation->locale => new CropStageTranslationResource($translation)],
                )->all(),
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

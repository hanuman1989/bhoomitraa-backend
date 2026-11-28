<?php

namespace App\Http\Resources\Admin;

use App\Models\Crop;
use App\Models\SowingMethod;
use App\Models\SowingMethodTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SowingMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $detail = $this->resource->relationLoaded('images');

        return [
            'id' => $this->id,
            ...$this->resource->only($this->resource->getFillable()),
            'title' => $this->resource->translation()?->title,
            'crops' => $this->whenLoaded('crops', fn (): array => $this->crops->map(fn (Crop $crop): array => [
                'id' => $crop->id, 'name' => $crop->translatedName(),
            ])->all()),
            'translations' => $this->when($detail, fn (): object => (object) $this->translations->mapWithKeys(fn (SowingMethodTranslation $row): array => [
                $row->locale => $row->only(SowingMethod::TRANSLATED_FIELDS),
            ])->all()),
            'images' => $this->when($detail, fn (): AnonymousResourceCollection => SowingMethodImageResource::collection($this->images)),
            'cover_image' => $this->whenLoaded('coverImage', fn (): ?SowingMethodImageResource => $this->coverImage === null ? null : new SowingMethodImageResource($this->coverImage)),
            'is_complete' => $this->when($detail, fn (): bool => $this->readiness_errors === []),
            'readiness_errors' => $this->when($detail, fn (): object => (object) $this->readiness_errors),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

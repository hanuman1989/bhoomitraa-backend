<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropVarietyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $translation = $this->resource->relationLoaded('translations') ? ($this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'))) : null;

        return [
            'id' => $this->id, ...$this->resource->only(['crop_id', 'slug', 'variety_code', 'maturity_days_min', 'maturity_days_max']),
            'name' => $this->localized_name ?? $translation?->name,
            'description' => $translation?->description,
            'region_notes' => $translation?->region_notes,
            'sources' => $this->whenLoaded('knowledgeSources', fn () => $this->knowledgeSources->map(fn ($source): array => [
                'id' => $source->id, 'title' => $source->translatedTitle(), 'source_url' => $source->source_url,
                'publisher' => $source->publisher, 'reference_identifier' => $source->reference_identifier,
            ])),
        ];
    }
}

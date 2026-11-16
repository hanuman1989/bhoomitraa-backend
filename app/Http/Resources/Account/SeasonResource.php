<?php

namespace App\Http\Resources\Account;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $translation = $this->resource->relationLoaded('translations') ? ($this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'))) : null;

        return [
            'id' => $this->id, ...$this->resource->only(['slug', ...Season::PRESENTATION_FIELDS]),
            'name' => $this->localized_name ?? $translation?->name,
            'description' => $translation?->description,
        ];
    }
}

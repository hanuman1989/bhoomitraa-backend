<?php

namespace App\Http\Resources\Account;

use App\Http\Resources\Admin\FertilizerImageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FertilizerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translation = $this->resource->translation();

        return ['id' => $this->id, ...$this->resource->only($this->resource->getFillable()),
            'name' => $translation?->name, 'description' => $translation?->description, 'usage_instructions' => $translation?->usage_instructions,
            'verification_status' => $this->verification_status,
            'image' => $this->images->isEmpty() ? null : (new FertilizerImageResource($this->images->first()))->resolve($request)['image_url'],
            'images' => FertilizerImageResource::collection($this->whenLoaded('images')),
            'nutrients' => $this->whenLoaded('nutrients', fn () => $this->nutrients->map->only(['nutrient_code', 'content_value', 'content_unit_code']))];
    }
}

<?php

namespace App\Http\Resources\Account;

use App\Http\Resources\Admin\SeedImageResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeedResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translation = $this->resource->translation();

        return ['id' => $this->id, ...$this->resource->only($this->resource->getFillable()),
            'name' => $translation?->name, 'description' => $translation?->description,
            'planting_instructions' => $translation?->planting_instructions, 'verification_status' => $this->verification_status,
            'image' => $this->images->isEmpty() ? null : (new SeedImageResource($this->images->first()))->resolve($request)['image_url'],
            'images' => SeedImageResource::collection($this->whenLoaded('images')),
            'crop' => $this->whenLoaded('crop', fn () => new CropResource($this->crop)),
            'crop_variety' => $this->whenLoaded('cropVariety', fn () => $this->cropVariety === null ? null : new CropVarietyResource($this->cropVariety))];
    }
}

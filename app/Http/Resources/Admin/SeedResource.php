<?php

namespace App\Http\Resources\Admin;

use App\Models\SeedTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class SeedResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $translation = $this->resource->translation();
        $detail = $this->resource->relationLoaded('images');

        return [
            'id' => $this->id, 'seed_id' => $this->id,
            ...$this->resource->only($this->resource->getFillable()),
            'name' => $translation?->name,
            'description' => $this->when($detail, fn (): ?string => $translation?->description),
            'planting_instructions' => $this->when($detail, fn (): ?string => $translation?->planting_instructions),
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName()),
            'crop_variety_name' => $this->whenLoaded('cropVariety', fn (): ?string => $this->cropVariety?->translatedName()),
            'source_id' => $this->source_id,
            'source' => $this->whenLoaded('knowledgeSource', fn (): ?array => $this->knowledgeSource === null ? null : [
                'id' => $this->knowledgeSource->id, 'title' => $this->knowledgeSource->translatedTitle(), 'publisher' => $this->knowledgeSource->publisher,
                'source_type' => $this->knowledgeSource->source_type, 'source_url' => $this->knowledgeSource->source_url,
                'reference_identifier' => $this->knowledgeSource->reference_identifier,
            ]),
            'verification_status' => $this->verification_status, 'is_active' => $this->is_active, 'requested_is_active' => $this->requested_is_active,
            'setup_status' => $this->setup_status, 'completed_step' => $this->completed_step,
            'next_step' => $this->completed_step >= 4 ? null : $this->completed_step + 1,
            'translations' => $this->when($detail, fn (): object => (object) $this->translations->mapWithKeys(fn (SeedTranslation $row): array => [
                $row->locale => $row->only(['name', 'description', 'planting_instructions']),
            ])->all()),
            'primary_image' => $this->whenLoaded('primaryImage', fn (): ?SeedImageResource => $this->primaryImage === null ? null : new SeedImageResource($this->primaryImage)),
            'images' => $this->when($detail, fn (): AnonymousResourceCollection => SeedImageResource::collection($this->images)),
            'ready_to_finalize' => $this->when(array_key_exists('readiness_errors', $this->resource->getAttributes()), fn (): bool => $this->readiness_errors === []),
            'readiness_errors' => $this->when(array_key_exists('readiness_errors', $this->resource->getAttributes()), fn (): object => (object) $this->readiness_errors),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

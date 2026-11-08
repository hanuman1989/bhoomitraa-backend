<?php

namespace App\Http\Resources\Admin;

use App\Models\FertilizerNutrient;
use App\Models\FertilizerTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class FertilizerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $translation = $this->resource->translation();
        $detail = $this->resource->relationLoaded('images');

        return [
            'id' => $this->id, 'fertilizer_id' => $this->id, 'slug' => $this->slug, 'fertilizer_code' => $this->fertilizer_code,
            'fertilizer_type' => $this->fertilizer_type, 'form' => $this->form, 'brand_name' => $this->brand_name,
            'manufacturer_name' => $this->manufacturer_name, 'grade' => $this->grade, 'default_unit_code' => $this->default_unit_code,
            'reference_pack_quantity' => $this->reference_pack_quantity, 'reference_pack_unit_code' => $this->reference_pack_unit_code,
            'application_method' => $this->application_method, 'name' => $translation?->name,
            'description' => $this->when($detail, fn (): ?string => $translation?->description),
            'usage_instructions' => $this->when($detail, fn (): ?string => $translation?->usage_instructions),
            'composition_notes' => $this->when($detail, fn (): ?string => $this->composition_notes),
            'source_id' => $this->source_id,
            'source' => $this->whenLoaded('knowledgeSource', fn (): ?array => $this->knowledgeSource === null ? null : [
                'id' => $this->knowledgeSource->id, 'title' => $this->knowledgeSource->translatedTitle(), 'publisher' => $this->knowledgeSource->publisher,
                'source_type' => $this->knowledgeSource->source_type, 'source_url' => $this->knowledgeSource->source_url,
                'reference_identifier' => $this->knowledgeSource->reference_identifier,
            ]),
            'verification_status' => $this->verification_status, 'is_active' => $this->is_active, 'requested_is_active' => $this->requested_is_active,
            'setup_status' => $this->setup_status, 'completed_step' => $this->completed_step, 'next_step' => $this->completed_step >= 5 ? null : $this->completed_step + 1,
            'nutrients' => $this->whenLoaded('nutrients', fn (): array => $this->nutrients->map(fn (FertilizerNutrient $row): array => [
                'id' => $row->id, 'nutrient_code' => $row->nutrient_code, 'content_value' => $row->content_value, 'content_unit_code' => $row->content_unit_code,
            ])->all()),
            'translations' => $this->when($detail, fn (): object => (object) $this->translations->mapWithKeys(fn (FertilizerTranslation $row): array => [
                $row->locale => $row->only(['name', 'description', 'usage_instructions']),
            ])->all()),
            'primary_image' => $this->whenLoaded('primaryImage', fn (): ?FertilizerImageResource => $this->primaryImage === null ? null : new FertilizerImageResource($this->primaryImage)),
            'images' => $this->when($detail, fn (): AnonymousResourceCollection => FertilizerImageResource::collection($this->images)),
            'ready_to_finalize' => $this->when(array_key_exists('readiness_errors', $this->resource->getAttributes()), fn (): bool => $this->readiness_errors === []),
            'readiness_errors' => $this->when(array_key_exists('readiness_errors', $this->resource->getAttributes()), fn (): object => (object) $this->readiness_errors),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

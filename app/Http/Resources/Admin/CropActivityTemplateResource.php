<?php

namespace App\Http\Resources\Admin;

use App\Models\CropActivityTemplateTranslation;
use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropActivityTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName($locale)),
            'crop_variety_id' => $this->crop_variety_id,
            'crop_variety_name' => $this->whenLoaded('cropVariety', fn (): ?string => $this->cropVariety?->translatedName($locale)),
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn (): ?string => $this->category?->translatedName($locale)),
            'crop_stage_id' => $this->crop_stage_id,
            'crop_stage_name' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->translatedName($locale)),
            'season_id' => $this->season_id,
            'season_name' => $this->whenLoaded('season', fn (): ?string => $this->season?->translatedName($locale)),
            'schedule_type' => $this->schedule_type,
            'start_offset_days' => $this->start_offset_days,
            'end_offset_days' => $this->end_offset_days,
            'region_scope' => $this->region_scope,
            'region_ids' => $this->whenLoaded('regions', fn (): array => array_map('intval', $this->regions->modelKeys())),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): array => [
                'id' => $region->id, 'parent_id' => $region->parent_id, 'region_type' => $region->region_type,
                'name' => $region->relationLoaded('translations') ? $region->translatedName($locale) : null,
            ])->all()),
            'priority' => $this->priority,
            'source_id' => $this->source_id,
            'source_title' => $this->whenLoaded('source', fn (): ?string => $this->source?->translatedTitle($locale)),
            'source' => $this->when(! $request->routeIs('admin.crop-activity-templates.index') && $this->resource->relationLoaded('source'), fn (): ?array => $this->source === null ? null : [
                'id' => $this->source->id, 'title' => $this->source->translatedTitle($locale), 'publisher' => $this->source->publisher,
                'source_type' => $this->source->source_type, 'source_url' => $this->source->source_url,
                'language' => $this->source->language, 'reference_identifier' => $this->source->reference_identifier,
                'verification_status' => $this->source->verification_status, 'is_active' => $this->source->is_active,
            ]),
            'is_active' => $this->is_active,
            'title' => $this->resource->getAttribute('localized_title')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->title, null),
            'description' => $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->description),
            'instructions' => $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->instructions),
            'translations' => $this->whenLoaded('translations', fn (): object => (object) $this->translations->mapWithKeys(
                fn (CropActivityTemplateTranslation $translation): array => [$translation->locale => new CropActivityTemplateTranslationResource($translation)],
            )->all()),
            'publication_warnings' => $this->when($this->resource->hasAttribute('potential_overlap_ids'), fn (): array => $this->potential_overlap_ids === [] ? [] : [[
                'code' => 'potential_overlap', 'template_ids' => $this->potential_overlap_ids,
                'message' => 'Potential scope and scheduling overlaps were found (up to 20 matches). Review their applicability; no template precedence policy is defined.',
            ]]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

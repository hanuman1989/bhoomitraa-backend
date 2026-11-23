<?php

namespace App\Http\Resources\Admin;

use App\Models\CropActivityTemplateTranslation;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropActivityTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'crop_id' => $this->crop_id,
            'crop_name' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->translatedName($locale)),
            'crop_color' => $this->whenLoaded('crop', fn (): ?string => $this->crop?->crop_color),
            'category_id' => $this->category_id,
            'category_name' => $this->whenLoaded('category', fn (): ?string => $this->category?->translatedName($locale)),
            'crop_stage_id' => $this->crop_stage_id,
            'crop_stage_name' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->translatedName($locale)),
            'variety_scope' => $this->variety_scope,
            'crop_variety_ids' => $this->whenLoaded('varieties', fn (): array => array_map('intval', $this->varieties->modelKeys())),
            'varieties' => $this->whenLoaded('varieties', fn (): array => $this->varieties->map(
                fn ($variety): array => ['id' => $variety->id, 'name' => $variety->translatedName($locale)],
            )->all()),
            'season_scope' => $this->season_scope,
            'season_ids' => $this->whenLoaded('seasons', fn (): array => array_map('intval', $this->seasons->modelKeys())),
            'seasons' => $this->whenLoaded('seasons', fn (): array => $this->seasons->map(
                fn (Season $season): array => [
                    'id' => $season->id,
                    'slug' => $season->slug,
                    ...$season->only(Season::PRESENTATION_FIELDS),
                    'name' => $season->translatedName($locale),
                ],
            )->all()),
            'region_scope' => $this->region_scope,
            'region_ids' => $this->resource->relationLoaded('regions')
                ? array_map('intval', $this->regions->modelKeys())
                : $this->when($this->resource->hasAttribute('listed_region_ids'), fn (): array => $this->listed_region_ids),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(
                fn (Region $region): array => [
                    'id' => $region->id,
                    'parent_id' => $region->parent_id,
                    'region_type' => $region->region_type,
                    'name' => $region->relationLoaded('translations') ? $region->translatedName($locale) : null,
                ],
            )->all()),
            'schedule_type' => $this->schedule_type,
            'schedule_basis' => $this->schedule_basis,
            'start_offset_days' => $this->start_offset_days,
            'end_offset_days' => $this->end_offset_days,
            'timing_exception_reason' => $this->timing_exception_reason,
            'scheduling_reconfirmation_required' => (bool) $this->scheduling_reconfirmation_required,
            'display_order' => (int) $this->display_order,
            'activity_type' => $this->activity_type,
            'allow_status_update' => (bool) $this->allow_status_update,
            'allow_expenses' => (bool) $this->allow_expenses,
            'allow_labour' => (bool) $this->allow_labour,
            'allow_notes' => (bool) $this->allow_notes,
            'allow_attachments' => (bool) $this->allow_attachments,
            'allow_rescheduling' => (bool) $this->allow_rescheduling,
            'smart_actions' => is_array($this->smart_actions) ? array_values($this->smart_actions) : [],
            'knowledge_source_ids' => $this->whenLoaded('knowledgeSources', fn (): array => array_map('intval', $this->knowledgeSources->modelKeys())),
            'knowledge_sources' => $this->whenLoaded('knowledgeSources', fn (): array => $this->knowledgeSources->map(
                fn (KnowledgeSource $source): array => [
                    'id' => $source->id,
                    'title' => $source->translatedTitle($locale),
                    'publisher' => $source->publisher,
                    'source_type' => $source->source_type,
                    'source_url' => $source->source_url,
                    'language' => $source->language,
                    'reference_identifier' => $source->reference_identifier,
                    'verification_status' => $source->verification_status,
                    'is_active' => (bool) $source->is_active,
                ],
            )->all()),
            'priority' => $this->priority,
            'is_active' => (bool) $this->is_active,
            'version' => (int) $this->version,
            'creation_request_id' => $this->creation_request_id,
            'title' => $this->resource->getAttribute('localized_title')
                ?? $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->title, null),
            'description' => $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->description),
            'instructions' => $this->whenLoaded('translations', fn (): ?string => $this->resource->translation($locale)?->instructions),
            'translations' => $this->whenLoaded('translations', fn (): object => (object) $this->translations->mapWithKeys(
                fn (CropActivityTemplateTranslation $translation): array => [$translation->locale => new CropActivityTemplateTranslationResource($translation)],
            )->all()),
            'translation_titles' => $this->when($this->resource->hasAttribute('translation_titles'), fn (): object => (object) $this->translation_titles),
            'timing' => $this->when($this->resource->hasAttribute('timeline_assessment'), fn (): array => $this->timeline_assessment['timing']),
            'readiness' => $this->when($this->resource->hasAttribute('timeline_assessment'), fn (): array => $this->timeline_assessment['readiness']),
            'publication_warnings' => $this->when($this->resource->hasAttribute('potential_overlap_ids'), fn (): array => $this->potential_overlap_ids === [] ? [] : [[
                'code' => 'potential_overlap',
                'template_ids' => $this->potential_overlap_ids,
                'message' => 'Potential scope and scheduling overlaps were found (up to 20 matches). Review their applicability; no template precedence policy is defined.',
            ]]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

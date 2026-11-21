<?php

namespace App\Http\Resources\Admin;

use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CropStageTimelineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'crop_id' => $this->whenLoaded('cropStage', fn (): ?int => $this->cropStage?->crop_id),
            'crop_name' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->relationLoaded('crop')
                ? $this->cropStage->crop?->translatedName($locale) : null),
            'crop_color' => $this->whenLoaded('cropStage', fn (): ?string => $this->cropStage?->relationLoaded('crop')
                ? $this->cropStage->crop?->crop_color : null),
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
            'region_scope' => $this->when(! $request->routeIs('admin.crop-stage-timelines.index'), $this->region_scope),
            'region_ids' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): int => (int) $region->id)->all()),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): array => [
                'id' => $region->id,
                'parent_id' => $region->parent_id,
                'name' => $region->relationLoaded('translations') ? $region->translatedName($locale) : null,
                'region_type' => $region->region_type,
            ])->all()),
            'days_after_sowing_min' => $this->days_after_sowing_min,
            'days_after_sowing_max' => $this->days_after_sowing_max,
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
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

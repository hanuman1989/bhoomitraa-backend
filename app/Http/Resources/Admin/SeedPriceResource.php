<?php

namespace App\Http\Resources\Admin;

use App\Models\Region;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeedPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seed_id' => $this->seed_id, 'seller_name' => $this->seller_name, 'price_type' => $this->price_type,
            'package_quantity' => $this->package_quantity, 'package_unit_code' => $this->package_unit_code,
            'currency_code' => $this->currency_code, 'package_price' => $this->package_price,
            'region_scope' => $this->region_scope, 'source_id' => $this->source_id, 'is_active' => $this->is_active,
            'effective_from' => $this->effective_from?->toDateString(), 'effective_to' => $this->effective_to?->toDateString(),
            'last_verified_at' => $this->last_verified_at?->toISOString(),
            'seed' => $this->whenLoaded('seed', fn (): ?array => $this->seed === null ? null : [
                'id' => $this->seed->id, 'name' => $this->seed->translation()?->name, 'sku' => $this->seed->sku, 'material_type' => $this->seed->material_type,
                'crop_id' => $this->seed->crop_id, 'crop_name' => $this->seed->crop?->translatedName(),
                'crop_variety_id' => $this->seed->crop_variety_id, 'crop_variety_name' => $this->seed->cropVariety?->translatedName(),
                'default_unit_code' => $this->seed->default_unit_code, 'reference_pack_quantity' => $this->seed->reference_pack_quantity,
                'reference_pack_unit_code' => $this->seed->reference_pack_unit_code,
                'primary_image' => $this->seed->primaryImage === null ? null : new SeedImageResource($this->seed->primaryImage),
            ]),
            'source_title' => $this->whenLoaded('knowledgeSource', fn (): ?string => $this->knowledgeSource?->translatedTitle()),
            'knowledge_source' => $this->whenLoaded('knowledgeSource', fn (): ?array => $this->knowledgeSource === null ? null : [
                'id' => $this->knowledgeSource->id, 'title' => $this->knowledgeSource->translatedTitle(), 'publisher' => $this->knowledgeSource->publisher,
                'source_type' => $this->knowledgeSource->source_type, 'source_url' => $this->knowledgeSource->source_url, 'reference_identifier' => $this->knowledgeSource->reference_identifier,
            ]),
            'region_ids' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): int => $region->id)->all()),
            'regions' => $this->whenLoaded('regions', fn (): array => $this->regions->map(fn (Region $region): array => [
                'id' => $region->id, 'parent_id' => $region->parent_id, 'name' => $region->translatedName(), 'region_type' => $region->region_type, 'is_active' => $region->is_active,
            ])->all()),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

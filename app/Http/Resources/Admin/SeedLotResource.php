<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeedLotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, ...$this->resource->only($this->resource->getFillable()),
            'verification_status' => $this->verification_status, 'verified_by' => $this->verified_by,
            'verified_at' => $this->verified_at?->toISOString(),
            'production_date' => $this->production_date?->toDateString(), 'test_date' => $this->test_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
            'computed_validity_status' => $this->resource->validityStatus($request->attributes->get('seed_lot_today')),
            'report_url' => $this->report_file === null ? null : route('admin.seed-lots.report', ['seedLot' => $this->id]),
            'seed' => $this->whenLoaded('seed', fn (): ?array => $this->seed === null ? null : [
                'id' => $this->seed->id, 'name' => $this->seed->translation()?->name, 'sku' => $this->seed->sku,
                'material_type' => $this->seed->material_type, 'is_active' => $this->seed->is_active, 'setup_status' => $this->seed->setup_status,
                'crop_id' => $this->seed->crop_id, 'crop_name' => $this->seed->crop?->translatedName(),
                'crop_variety_id' => $this->seed->crop_variety_id, 'crop_variety_name' => $this->seed->cropVariety?->translatedName(),
                'primary_image' => $this->seed->primaryImage === null ? null : new SeedImageResource($this->seed->primaryImage),
            ]),
            'verifier' => $this->whenLoaded('verifier', fn (): ?array => $this->verifier === null ? null : [
                'id' => $this->verifier->id, 'first_name' => $this->verifier->first_name, 'last_name' => $this->verifier->last_name,
            ]),
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(), 'added_at' => $this->created_at?->format('j M Y h:i A'),
        ];
    }
}

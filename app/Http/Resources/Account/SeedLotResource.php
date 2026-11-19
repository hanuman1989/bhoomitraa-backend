<?php

namespace App\Http\Resources\Account;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeedLotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, ...$this->resource->only(['seed_id', 'lot_number', 'seed_class', 'germination_percentage',
            'physical_purity_percentage', 'genetic_purity_percentage', 'seed_treatment_status', 'certification_number', 'certification_body', 'verification_status']),
            'production_date' => $this->production_date?->toDateString(), 'test_date' => $this->test_date?->toDateString(),
            'valid_until' => $this->valid_until?->toDateString()];
    }
}

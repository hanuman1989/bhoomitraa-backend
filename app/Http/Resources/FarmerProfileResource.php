<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmerProfileResource extends JsonResource
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
            'profile_image' => $this->profile_image,
            'state_id' => $this->state_id,
            'district_id' => $this->district_id,
            'state' => $this->whenLoaded('state', fn (): array => [
                'id' => $this->state->id, 'name' => $this->state->localized_name,
            ]),
            'district' => $this->whenLoaded('district', fn (): array => [
                'id' => $this->district->id, 'name' => $this->district->localized_name,
            ]),
            'village' => $this->village,
            'address' => $this->address,
            'pincode' => $this->pincode,
            'preferred_language' => $this->preferred_language,
            'preferred_units' => $this->preferred_units,
        ];
    }
}

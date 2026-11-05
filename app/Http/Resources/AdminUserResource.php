<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'status' => $this->status,
            'is_super_user' => $this->is_super_user,
            'created_at' => $this->created_at?->toISOString(),
            'added_at' => $this->created_at?->format('j M Y h:i A'),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

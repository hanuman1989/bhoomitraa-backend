<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FarmerSmartTimelinePreviewResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'preview_only' => true,
            'input' => $this->resource['input'],
            'selection' => $this->resource['selection'],
            'growth_stages' => $this->resource['growth_stages'],
            'activities' => $this->resource['activities'],
            'summary' => $this->resource['summary'],
            'warnings' => $this->resource['warnings'],
        ];
    }
}

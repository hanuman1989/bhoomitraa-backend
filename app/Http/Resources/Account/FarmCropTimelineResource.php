<?php

namespace App\Http\Resources\Account;

use App\Services\CropTimeline\FarmCropTimelinePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The whole farmer crop timeline for one FarmCropCycle, display-ready in the request locale. */
class FarmCropTimelineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return app(FarmCropTimelinePresenter::class)->timeline($this->resource);
    }
}

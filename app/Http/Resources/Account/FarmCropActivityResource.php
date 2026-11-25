<?php

namespace App\Http\Resources\Account;

use App\Models\FarmCropActivity;
use App\Services\CropTimeline\FarmCropTimelinePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A changed activity with the refreshed timeline totals and its section progress. */
class FarmCropActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FarmCropActivity $activity */
        $activity = $this->resource;

        return app(FarmCropTimelinePresenter::class)->mutation($activity->cycle()->firstOrFail(), $activity->id);
    }
}

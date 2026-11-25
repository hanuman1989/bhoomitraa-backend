<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\FarmCropTimelineResource;
use App\Models\FarmCropCycle;
use App\Services\CropTimeline\FarmCropTimelineGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Farmer crop timeline: read, generate once, and dismiss the one-time "ready" alert. Owner-scoped (404 for others). */
class FarmCropTimelineController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->respond($request, $this->ownedCycle($request, $id), __('crop_timeline.messages.retrieved'));
    }

    public function generate(Request $request, string $id, FarmCropTimelineGenerator $generator): JsonResponse
    {
        $result = $generator->generate((int) $request->user('user')->id, (int) $id);

        return $this->respond($request, $result['cycle'], __('crop_timeline.messages.generated'), ['skipped' => $result['skipped']]);
    }

    public function alertSeen(Request $request, string $id): JsonResponse
    {
        $cycle = DB::transaction(function () use ($request, $id): FarmCropCycle {
            $cycle = FarmCropCycle::query()->ownedBy((int) $request->user('user')->id)->lockForUpdate()->findOrFail($id);
            if ($cycle->timeline_generated_at !== null && $cycle->timeline_alert_seen_at === null) {
                $cycle->forceFill(['timeline_alert_seen_at' => now()])->save();
            }

            return $cycle;
        });

        return response()->json(['status' => true, 'data' => ['alert' => ['show' => false], 'generated' => $cycle->timeline_generated_at !== null],
            'pagination' => null, 'message' => __('crop_timeline.messages.alert_seen')]);
    }

    private function ownedCycle(Request $request, string $id): FarmCropCycle
    {
        return FarmCropCycle::query()->ownedBy((int) $request->user('user')->id)->findOrFail($id);
    }

    /** @param  array<string, mixed>  $extra */
    private function respond(Request $request, FarmCropCycle $cycle, string $message, array $extra = []): JsonResponse
    {
        return response()->json(['status' => true, 'data' => [...(new FarmCropTimelineResource($cycle))->resolve($request), ...$extra],
            'pagination' => null, 'message' => $message]);
    }
}

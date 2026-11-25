<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RescheduleFarmCropActivityRequest;
use App\Http\Requests\Account\StoreFarmCropActivityNoteRequest;
use App\Http\Requests\Account\UpdateFarmCropActivityStatusRequest;
use App\Http\Resources\Account\FarmCropActivityResource;
use App\Models\FarmCropActivity;
use App\Models\FarmCropActivityNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Farmer crop activity changes. Every lookup is scoped to the signed-in farmer's farms, so
 * another farmer's activity or note returns 404. Each response carries the updated activity and totals.
 */
class FarmCropActivityController extends Controller
{
    public function updateStatus(UpdateFarmCropActivityStatusRequest $request, string $activity): JsonResponse
    {
        $action = $request->validated('action');
        $record = DB::transaction(function () use ($request, $activity, $action): FarmCropActivity {
            $record = $this->ownedActivity($request, $activity);
            if (! $record->tracksStatus()) {
                throw ValidationException::withMessages(['action' => [__('crop_timeline.errors.status_disabled')]]);
            }
            if ($action === 'complete' && $record->status !== 'completed') {
                // Completing is allowed before the start date.
                $record->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            } elseif ($action === 'reopen' && $record->status === 'completed') {
                $hasRecords = $record->notes()->exists();
                $record->forceFill([
                    'status' => $hasRecords ? 'in_progress' : 'pending', 'completed_at' => null,
                    'started_at' => $hasRecords ? ($record->started_at ?? now()) : null,
                ])->save();
            }

            return $record;
        });

        return $this->respond($record, __($action === 'complete' ? 'crop_timeline.messages.completed' : 'crop_timeline.messages.reopened'));
    }

    public function reschedule(RescheduleFarmCropActivityRequest $request, string $activity): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $activity): FarmCropActivity {
            $record = $this->ownedActivity($request, $activity);
            if (! $record->template->allow_rescheduling) {
                throw ValidationException::withMessages(['start_date' => [__('crop_timeline.errors.reschedule_disabled')]]);
            }
            if ($record->tracksStatus() && $record->status === 'completed') {
                throw ValidationException::withMessages(['start_date' => [__('crop_timeline.errors.reschedule_completed')]]);
            }
            // Only this activity moves; the planned dates are kept so it can be reset.
            $record->forceFill($request->wantsReset()
                ? ['start_date' => $record->planned_start_date, 'end_date' => $record->planned_end_date]
                : $request->safe()->only(['start_date', 'end_date']))->save();

            return $record;
        });

        return $this->respond($record, __($request->wantsReset() ? 'crop_timeline.messages.schedule_reset' : 'crop_timeline.messages.rescheduled'));
    }

    public function storeNote(StoreFarmCropActivityNoteRequest $request, string $activity): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $activity): FarmCropActivity {
            $record = $this->ownedActivity($request, $activity);
            if (! $record->template->allow_notes) {
                throw ValidationException::withMessages(['note' => [__('crop_timeline.errors.notes_disabled')]]);
            }
            $record->notes()->create([...$request->validated(), 'user_id' => (int) $request->user('user')->id]);
            $record->markRecordAdded();

            return $record;
        });

        return $this->respond($record, __('crop_timeline.messages.note_saved'), 201);
    }

    public function destroyNote(Request $request, string $note): JsonResponse
    {
        $record = DB::transaction(function () use ($request, $note): FarmCropActivity {
            $userId = (int) $request->user('user')->id;
            $model = FarmCropActivityNote::query()
                ->whereHas('activity.cycle', fn (Builder $cycle): Builder => $cycle->ownedBy($userId))
                ->lockForUpdate()->findOrFail($note);
            $record = $this->ownedActivity($request, (string) $model->farm_crop_activity_id);
            // Deleting records never reverts the activity status.
            $model->delete();

            return $record;
        });

        return $this->respond($record, __('crop_timeline.messages.note_deleted'));
    }

    /** Locks the owner's activity; changes are only allowed while the crop is active. */
    private function ownedActivity(Request $request, string $id): FarmCropActivity
    {
        $userId = (int) $request->user('user')->id;
        $record = FarmCropActivity::query()
            ->whereHas('cycle', fn (Builder $cycle): Builder => $cycle->ownedBy($userId))
            ->with(['template', 'cycle'])->lockForUpdate()->findOrFail($id);
        abort_unless($record->cycle->status === 'active', 409, __('crop_timeline.errors.historical'));

        return $record;
    }

    private function respond(FarmCropActivity $record, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'data' => (new FarmCropActivityResource($record))->resolve(request()),
            'pagination' => null, 'message' => $message], $status);
    }
}

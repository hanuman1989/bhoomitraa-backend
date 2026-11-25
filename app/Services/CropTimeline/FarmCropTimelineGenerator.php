<?php

namespace App\Services\CropTimeline;

use App\Models\FarmCropActivity;
use App\Models\FarmCropCycle;
use App\Services\FarmerSmartTimelineGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Saves a farmer's crop timeline from the existing template resolver. Idempotent: a cycle that
 * already has a timeline is returned unchanged. Templates without usable timing are skipped, never guessed.
 */
class FarmCropTimelineGenerator
{
    /** Resolver warnings that mean a matching template was left out of the timeline. */
    private const SKIP_CODES = [
        'ACTIVITY_TEMPLATE_INELIGIBLE', 'INVALID_ACTIVITY_DAY_RANGE', 'HARVEST_DATE_NOT_AVAILABLE',
        'UNSUPPORTED_SCHEDULE_TYPE', 'DATE_RANGE_UNSUPPORTED', 'STAGE_WINDOW_NOT_AVAILABLE',
    ];

    public function __construct(private FarmerSmartTimelineGenerator $resolver) {}

    /** @return array{cycle: FarmCropCycle, created: bool, skipped: int} */
    public function generate(int $userId, int $cycleId): array
    {
        return DB::transaction(function () use ($userId, $cycleId): array {
            $cycle = FarmCropCycle::query()->ownedBy($userId)->with('farm')->lockForUpdate()->findOrFail($cycleId);
            if ($cycle->timeline_generated_at !== null) {
                return ['cycle' => $cycle, 'created' => false, 'skipped' => (int) ($cycle->planning_snapshot['timeline']['skipped'] ?? 0)];
            }
            if ($cycle->status !== 'active' || $cycle->sowing_date === null) {
                throw ValidationException::withMessages(['timeline' => [__('crop_timeline.errors.not_active')]]);
            }
            $district = collect($cycle->farm->regionContext()['applicable_regions'])->firstWhere('region_type', 'district');
            if ($district === null) {
                throw ValidationException::withMessages(['timeline' => [__('crop_timeline.errors.no_district')]]);
            }

            $result = $this->resolver->generate([
                'crop_id' => $cycle->crop_id, 'crop_variety_id' => $cycle->crop_variety_id, 'season_id' => $cycle->season_id,
                'sowing_date' => $cycle->sowing_date->toDateString(), 'district_id' => (int) $district['id'],
                'expected_harvest_date' => $cycle->expected_harvest_date_min?->toDateString(),
            ]);

            foreach ($result['activities'] as $activity) {
                FarmCropActivity::query()->create([
                    'farm_crop_cycle_id' => $cycle->id, 'crop_activity_template_id' => $activity['template_id'],
                    'planned_start_date' => $activity['start_date'], 'planned_end_date' => $activity['end_date'],
                    'start_date' => $activity['start_date'], 'end_date' => $activity['end_date'], 'status' => 'pending',
                ]);
            }
            $skippedIds = collect($result['warnings'])
                ->filter(fn (array $warning): bool => in_array($warning['code'], self::SKIP_CODES, true) && isset($warning['context']->template_id))
                ->map(fn (array $warning): int => (int) $warning['context']->template_id)->unique()->values()->all();

            $snapshot = $cycle->planning_snapshot ?? [];
            // Stage windows are kept as days after sowing so a sowing-date change moves them automatically.
            $snapshot['timeline'] = [
                'stages' => array_map(fn (array $stage): array => [
                    'crop_stage_id' => $stage['crop_stage_id'], 'sequence' => $stage['sequence'],
                    'start_day' => $stage['start_day'], 'end_day' => $stage['end_day'],
                ], $result['growth_stages']),
                'skipped' => count($skippedIds),
            ];
            $cycle->forceFill(['planning_snapshot' => $snapshot, 'timeline_generated_at' => now()])->save();

            if ($skippedIds !== []) {
                Log::info('Crop timeline templates skipped', [
                    'farm_crop_cycle_id' => $cycle->id, 'skipped_template_ids' => $skippedIds,
                ]);
            }

            return ['cycle' => $cycle, 'created' => true, 'skipped' => count($skippedIds)];
        }, attempts: 3);
    }

    /**
     * Moves every timeline date by the sowing-date change. Activities the farmer rescheduled keep
     * their own dates; their planned dates still move so "Back to planned dates" stays correct.
     */
    public function shiftForSowingChange(FarmCropCycle $cycle, int $days): void
    {
        if ($days === 0 || $cycle->timeline_generated_at === null) {
            return;
        }
        foreach ($cycle->activities()->lockForUpdate()->get() as $activity) {
            $rescheduled = $activity->isRescheduled();
            $activity->planned_start_date = $activity->planned_start_date->addDays($days);
            $activity->planned_end_date = $activity->planned_end_date->addDays($days);
            if (! $rescheduled) {
                $activity->start_date = $activity->planned_start_date;
                $activity->end_date = $activity->planned_end_date;
            }
            $activity->save();
        }
    }
}

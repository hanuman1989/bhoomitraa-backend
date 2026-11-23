<?php

namespace App\Services;

use App\Models\CropActivityTemplate;

/**
 * Display-oriented scheduling and readiness assessment for the timeline builder.
 *
 * Timing codes, in precedence order: pending, not_applicable, conflict, missing, outside, partial, valid.
 * Readiness reasons are advisory, except activation requirements, which the status endpoint enforces.
 */
class CropActivityTemplateAssessor
{
    public function __construct(private StageTimelineResolver $resolver) {}

    /**
     * @param  list<int>|null  $regionIds  Preloaded district IDs; resolved from the template when null.
     * @return array{timing: array<string, mixed>, readiness: array{configured: bool, reasons: list<array{code: string, field: string|null, message: string}>}}
     */
    public function assess(CropActivityTemplate $template, ?array $regionIds = null): array
    {
        $candidate = clone $template;
        $regionIds ??= $candidate->resolveRegionIds([]);
        $varietyIds = $candidate->resolveVarietyIds([]);
        $seasonIds = $candidate->resolveSeasonIds([]);
        $timing = $this->timing($candidate, $varietyIds, $seasonIds, $regionIds);

        $reasons = [];
        if ($candidate->schedule_basis === CropActivityTemplate::PENDING_SCHEDULE_BASIS) {
            $reasons[] = $this->reason('scheduling_pending', 'schedule_basis', 'Scheduling has not been configured yet.');
        }
        $candidate->is_active = true;
        foreach ($candidate->validationErrors($regionIds, [], false, $varietyIds, $seasonIds, $candidate->resolveKnowledgeSourceIds([])) as $field => $messages) {
            if ($field === 'schedule_basis' && $candidate->schedule_basis === CropActivityTemplate::PENDING_SCHEDULE_BASIS) {
                continue;
            }
            foreach ($messages as $message) {
                $reasons[] = $this->reason('activation_requirement', $field, $message);
            }
        }
        if ($candidate->schedule_basis === 'growth_stage' && $timing['code'] === 'conflict') {
            $reasons[] = $this->reason('stage_timing_conflict', 'crop_stage_id', 'Equally specific active stage timelines disagree for some applicable contexts.');
        }
        if ($candidate->schedule_basis === 'growth_stage' && $timing['code'] === 'missing') {
            $reasons[] = $this->reason('stage_timing_missing', 'crop_stage_id', 'No active stage timeline covers some applicable contexts.');
        }
        if ($timing['timing_exception'] && blank($candidate->timing_exception_reason)) {
            $reasons[] = $this->reason('timing_exception_unjustified', 'timing_exception_reason', 'The fixed offsets fall outside the stage timing; record a justification.');
        }
        if ($candidate->scheduling_reconfirmation_required) {
            $reasons[] = $this->reason('scheduling_reconfirmation_required', 'schedule_basis', 'Stage timing changed after this schedule was saved; review and save the schedule again.');
        }

        return ['timing' => $timing, 'readiness' => ['configured' => $reasons === [], 'reasons' => $reasons]];
    }

    /**
     * @param  list<int>  $varietyIds
     * @param  list<int>  $seasonIds
     * @param  list<int>  $regionIds
     * @return array<string, mixed>
     */
    private function timing(CropActivityTemplate $template, array $varietyIds, array $seasonIds, array $regionIds): array
    {
        $offsets = $template->schedule_basis === 'fixed_offsets' && $template->start_offset_days !== null && $template->end_offset_days !== null
            ? ['start' => (int) $template->start_offset_days, 'end' => (int) $template->end_offset_days]
            : null;
        $result = [
            'code' => 'not_applicable', 'offset_fit' => null, 'timing_exception' => false, 'offset_window' => $offsets,
            'windows' => [], 'missing_count' => 0, 'conflict_count' => 0, 'total_contexts' => 0,
        ];
        if ($template->schedule_basis === CropActivityTemplate::PENDING_SCHEDULE_BASIS) {
            return [...$result, 'code' => 'pending'];
        }
        if ($template->crop_stage_id === null || $template->schedule_type !== 'after_sowing') {
            return $result;
        }
        $stage = $this->resolver->resolve(
            (int) $template->crop_id, [(int) $template->crop_stage_id],
            (string) $template->variety_scope, $varietyIds,
            (string) $template->season_scope, $seasonIds,
            (string) $template->region_scope, $regionIds,
        )[0];
        $windows = array_map(fn (array $window): array => [
            'min' => $window['min'], 'max' => $window['max'],
            'stage_timeline_ids' => $window['stage_timeline_ids'], 'context_count' => $window['context_count'],
        ], $stage['windows']);
        $fit = $offsets === null || $windows === [] ? null : $this->offsetFit($offsets, $windows);
        $code = match (true) {
            $stage['conflict_count'] > 0 => 'conflict',
            $windows === [] || $stage['missing_count'] > 0 => 'missing',
            $fit !== null => $fit,
            default => 'valid',
        };

        return [
            ...$result,
            'code' => $code,
            'offset_fit' => $fit,
            'timing_exception' => in_array($fit, ['partial', 'outside'], true),
            'windows' => $windows,
            'missing_count' => $stage['missing_count'],
            'conflict_count' => $stage['conflict_count'],
            'total_contexts' => $stage['total_contexts'],
        ];
    }

    /**
     * The worst fit across resolved windows: outside > partial > valid.
     *
     * @param  array{start: int, end: int}  $offsets
     * @param  list<array{min: int, max: int}>  $windows
     */
    private function offsetFit(array $offsets, array $windows): string
    {
        $fit = 'valid';
        foreach ($windows as $window) {
            if ($offsets['end'] < $window['min'] || $offsets['start'] > $window['max']) {
                return 'outside';
            }
            if ($offsets['start'] < $window['min'] || $offsets['end'] > $window['max']) {
                $fit = 'partial';
            }
        }

        return $fit;
    }

    /** @return array{code: string, field: string|null, message: string} */
    private function reason(string $code, ?string $field, string $message): array
    {
        return ['code' => $code, 'field' => $field, 'message' => $message];
    }
}

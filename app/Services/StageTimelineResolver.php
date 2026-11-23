<?php

namespace App\Services;

use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\Region;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Resolves which active Stage Timeline window applies to each eligible applicability context.
 *
 * A context is one (variety or generic, season, district) combination. Matching follows the
 * farmer timeline generator: an active timeline matches when its variety, season, and region
 * scopes cover the context. Only non-dominated matches are kept, ranked as exact variety +
 * district > exact variety + all > generic + district > generic + all. Equally ranked matches
 * that agree form one window; matches that disagree are a conflict; no match is missing coverage.
 * Districts are grouped by the set of district-specific timelines covering them, so totals count
 * real districts without enumerating each one individually.
 */
class StageTimelineResolver
{
    public const MAX_SAMPLE_CONTEXTS = 3;

    /** @var array<int, Collection<int, Collection<int, array{id: int, min: int, max: int, variety_scope: string, variety_ids: list<int>, season_scope: string, season_ids: list<int>, region_scope: string, region_ids: list<int>}>>> */
    private array $timelinesByCrop = [];

    /** @var array<int, list<int>> */
    private array $varietiesByCrop = [];

    /** @var list<int>|null */
    private ?array $activeSeasonIds = null;

    /** @var list<int>|null */
    private ?array $activeDistrictIds = null;

    /**
     * @param  list<int>  $stageIds
     * @param  list<int>  $varietyIds
     * @param  list<int>  $seasonIds
     * @param  list<int>  $regionIds
     * @return list<array<string, mixed>>
     */
    public function resolve(
        int $cropId,
        array $stageIds,
        string $varietyScope,
        array $varietyIds,
        string $seasonScope,
        array $seasonIds,
        string $regionScope,
        array $regionIds,
    ): array {
        $timelines = $this->timelines($cropId);
        $varieties = $varietyScope === 'all' ? [null, ...$this->activeVarietyIds($cropId)] : array_values($varietyIds);
        $seasons = $seasonScope === 'all' ? $this->activeSeasonIds() : array_values($seasonIds);
        if ($seasons === []) {
            $seasons = [null];
        }
        $districts = $regionScope === 'all' ? $this->activeDistrictIds() : array_values($regionIds);

        $result = [];
        foreach ($stageIds as $stageId) {
            $result[] = $this->resolveStage((int) $stageId, $timelines->get((int) $stageId, collect()), $varieties, $seasons, $districts);
        }

        return $result;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $stageTimelines
     * @param  list<int|null>  $varieties
     * @param  list<int|null>  $seasons
     * @param  list<int>  $districts
     * @return array<string, mixed>
     */
    private function resolveStage(int $stageId, Collection $stageTimelines, array $varieties, array $seasons, array $districts): array
    {
        $regionClasses = $this->regionClasses($stageTimelines, $districts);
        $windows = [];
        $conflicts = [];
        $missing = ['count' => 0, 'samples' => []];
        $total = 0;

        foreach ($varieties as $varietyId) {
            foreach ($seasons as $seasonId) {
                foreach ($regionClasses as $regionClass) {
                    $count = $regionClass['count'];
                    $total += $count;
                    $context = ['crop_variety_id' => $varietyId, 'season_id' => $seasonId, 'region_id' => $regionClass['sample_region_id']];
                    $matches = $stageTimelines->filter(fn (array $timeline): bool => $this->covers($timeline, $varietyId, $seasonId, $regionClass['specific_timeline_ids']));
                    if ($matches->isEmpty()) {
                        $this->addContext($missing, $count, $context);

                        continue;
                    }
                    $rank = $matches->max(fn (array $timeline): int => $this->rank($timeline));
                    $top = $matches->filter(fn (array $timeline): bool => $this->rank($timeline) === $rank)->sortBy('id')->values();
                    $ids = $top->pluck('id')->all();
                    $ranges = $top->map(fn (array $timeline): string => $timeline['min'].':'.$timeline['max'])->unique();
                    if ($ranges->count() > 1) {
                        $key = implode(',', $ids);
                        $conflicts[$key] ??= ['stage_timeline_ids' => $ids, 'count' => 0, 'samples' => []];
                        $this->addContext($conflicts[$key], $count, $context);

                        continue;
                    }
                    $key = $ranges->first();
                    $windows[$key] ??= ['min' => $top[0]['min'], 'max' => $top[0]['max'], 'stage_timeline_ids' => [], 'count' => 0, 'samples' => []];
                    $windows[$key]['stage_timeline_ids'] = array_values(array_unique([...$windows[$key]['stage_timeline_ids'], ...$ids]));
                    $this->addContext($windows[$key], $count, $context);
                }
            }
        }

        $windowList = collect($windows)->sortBy([['min', 'asc'], ['max', 'asc']])->map(fn (array $window): array => [
            'min' => $window['min'],
            'max' => $window['max'],
            'stage_timeline_ids' => collect($window['stage_timeline_ids'])->sort()->values()->all(),
            'context_count' => $window['count'],
            'sample_contexts' => $window['samples'],
        ])->values()->all();

        return [
            'crop_stage_id' => $stageId,
            'windows' => $windowList,
            'conflicts' => collect($conflicts)->map(fn (array $conflict): array => [
                'stage_timeline_ids' => $conflict['stage_timeline_ids'],
                'context_count' => $conflict['count'],
                'sample_contexts' => $conflict['samples'],
            ])->values()->all(),
            'missing_count' => $missing['count'],
            'missing_sample_contexts' => $missing['samples'],
            'conflict_count' => array_sum(array_column($conflicts, 'count')),
            'total_contexts' => $total,
        ];
    }

    /**
     * @param  array{count: int, samples: list<array<string, int|null>>}  $bucket
     * @param  array<string, int|null>  $context
     */
    private function addContext(array &$bucket, int $count, array $context): void
    {
        $bucket['count'] += $count;
        if (count($bucket['samples']) < self::MAX_SAMPLE_CONTEXTS) {
            $bucket['samples'][] = $context;
        }
    }

    /**
     * @param  array<string, mixed>  $timeline
     * @param  list<int>  $specificTimelineIds
     */
    private function covers(array $timeline, ?int $varietyId, ?int $seasonId, array $specificTimelineIds): bool
    {
        if ($timeline['variety_scope'] !== 'all' && ($varietyId === null || ! in_array($varietyId, $timeline['variety_ids'], true))) {
            return false;
        }
        if ($timeline['season_scope'] !== 'all' && ($seasonId === null || ! in_array($seasonId, $timeline['season_ids'], true))) {
            return false;
        }

        return $timeline['region_scope'] === 'all' || in_array($timeline['id'], $specificTimelineIds, true);
    }

    /** @param array<string, mixed> $timeline */
    private function rank(array $timeline): int
    {
        return ($timeline['variety_scope'] === 'all' ? 0 : 2) + ($timeline['region_scope'] === 'specific' ? 1 : 0);
    }

    /**
     * Group districts by the district-specific timelines that cover them.
     *
     * @param  Collection<int, array<string, mixed>>  $stageTimelines
     * @param  list<int>  $districts
     * @return list<array{specific_timeline_ids: list<int>, count: int, sample_region_id: int|null}>
     */
    private function regionClasses(Collection $stageTimelines, array $districts): array
    {
        if ($districts === []) {
            return [['specific_timeline_ids' => [], 'count' => 1, 'sample_region_id' => null]];
        }
        $specific = $stageTimelines->where('region_scope', 'specific');
        $classes = [];
        foreach ($districts as $districtId) {
            $ids = $specific->filter(fn (array $timeline): bool => in_array($districtId, $timeline['region_ids'], true))
                ->pluck('id')->sort()->values()->all();
            $key = implode(',', $ids);
            $classes[$key] ??= ['specific_timeline_ids' => $ids, 'count' => 0, 'sample_region_id' => $districtId];
            $classes[$key]['count']++;
        }

        return array_values($classes);
    }

    /** @return Collection<int, Collection<int, array<string, mixed>>> */
    private function timelines(int $cropId): Collection
    {
        return $this->timelinesByCrop[$cropId] ??= CropStageTimeline::query()
            ->where('is_active', true)
            ->whereHas('cropStage', fn ($stage) => $stage->where('crop_id', $cropId))
            ->with(['varieties:id', 'seasons:id', 'regions:id'])
            ->orderBy('id')->get()
            ->filter(fn (CropStageTimeline $timeline): bool => $this->validRange($timeline))
            ->map(fn (CropStageTimeline $timeline): array => [
                'id' => (int) $timeline->id,
                'crop_stage_id' => (int) $timeline->crop_stage_id,
                'min' => (int) $timeline->days_after_sowing_min,
                'max' => (int) $timeline->days_after_sowing_max,
                'variety_scope' => $timeline->variety_scope,
                'variety_ids' => array_map('intval', $timeline->varieties->modelKeys()),
                'season_scope' => $timeline->season_scope,
                'season_ids' => array_map('intval', $timeline->seasons->modelKeys()),
                'region_scope' => $timeline->region_scope,
                'region_ids' => array_map('intval', $timeline->regions->modelKeys()),
            ])->groupBy('crop_stage_id');
    }

    private function validRange(CropStageTimeline $timeline): bool
    {
        $min = filter_var($timeline->getRawOriginal('days_after_sowing_min'), FILTER_VALIDATE_INT);
        $max = filter_var($timeline->getRawOriginal('days_after_sowing_max'), FILTER_VALIDATE_INT);

        return $min !== false && $max !== false && $min >= 0 && $max >= $min;
    }

    /** @return list<int> */
    private function activeVarietyIds(int $cropId): array
    {
        return $this->varietiesByCrop[$cropId] ??= CropVariety::query()->where('crop_id', $cropId)
            ->where('is_active', true)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function activeSeasonIds(): array
    {
        return $this->activeSeasonIds ??= Season::query()->where('is_active', true)->orderBy('id')
            ->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** @return list<int> */
    private function activeDistrictIds(): array
    {
        return $this->activeDistrictIds ??= Region::query()->where('region_type', 'district')->where('is_active', true)
            ->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }
}

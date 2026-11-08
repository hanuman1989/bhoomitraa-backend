<?php

namespace App\Services;

use App\Models\Crop;
use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\CropStageTimeline;
use App\Models\CropVariety;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

class FarmerSmartTimelineGenerator
{
    /**
     * Calendar dates use a fixed timezone solely for arithmetic, with no timezone conversion.
     * No models are saved and no farmer records are created.
     *
     * @param  array{crop_id: int, crop_variety_id?: int|null, season_id: int, sowing_date: string, district_id: int}  $input
     * @return array<string, mixed>
     */
    public function generate(array $input): array
    {
        $input = [
            'crop_id' => (int) $input['crop_id'], 'crop_variety_id' => isset($input['crop_variety_id']) ? (int) $input['crop_variety_id'] : null,
            'season_id' => (int) $input['season_id'], 'sowing_date' => $input['sowing_date'], 'district_id' => (int) $input['district_id'],
        ];
        $crop = Crop::query()->published()->without('category')->find($input['crop_id']);
        $variety = $input['crop_variety_id'] === null ? null : CropVariety::query()->where('is_active', true)
            ->where('crop_id', $input['crop_id'])->with(['translations' => $this->names()])->find($input['crop_variety_id']);
        $season = Season::query()->where('is_active', true)->with(['translations' => $this->names()])->find($input['season_id']);
        $district = Region::query()->where('region_type', 'district')->where('is_active', true)
            ->with(['translations' => $this->names()])->find($input['district_id']);
        $errors = [];
        foreach (['crop_id' => $crop, 'season_id' => $season, 'district_id' => $district] as $field => $record) {
            if ($record === null) {
                $errors[$field] = ['Select an eligible active record.'];
            }
        }
        if ($input['crop_variety_id'] !== null && $variety === null) {
            $errors['crop_variety_id'] = ['Select an active variety belonging to the selected crop.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $warnings = [];
        $sourceEligibility = [];
        $this->warning($warnings, 'CONTENT_APPROVAL_LIMITATION', 'This preview checks configured publication requirements. Claim-level scientific approval is not recorded and remains a reviewer responsibility.');
        $selection = [
            'crop_name' => $this->name($crop, 'crop', $warnings),
            'crop_variety_name' => $variety === null ? null : $this->name($variety, 'crop_variety', $warnings),
            'season_name' => $this->name($season, 'season', $warnings),
            'district_name' => $this->name($district, 'region', $warnings),
        ];
        $sowingDate = CarbonImmutable::createFromFormat('!Y-m-d', $input['sowing_date'], 'UTC');
        $maxOffset = (int) $sowingDate->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', '9999-12-31', 'UTC'));
        $allStages = CropStage::query()->where('crop_id', $input['crop_id'])
            ->with(['translations' => $this->names()])->orderBy('sequence')->orderBy('id')->get()->keyBy('id');
        $stages = $allStages->where('is_active', true)->filter(function (CropStage $stage) use (&$warnings): bool {
            $eligible = $stage->sequence >= 1 && $stage->sequence <= 65535
                && is_string($stage->code) && mb_strlen($stage->code) <= 50 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $stage->code) === 1
                && $this->requiredNames($stage, CropStage::REQUIRED_LOCALES);
            if (! $eligible) {
                $this->warning($warnings, 'STAGE_INELIGIBLE', 'An active growth stage does not satisfy its publication requirements.', [
                    'crop_stage_id' => $stage->id, 'crop_stage_name' => $stage->translatedName(),
                ]);
            }

            return $eligible;
        });
        if ($stages->isEmpty()) {
            $this->warning($warnings, 'GROWTH_STAGES_NOT_FOUND', 'No eligible active growth stages are configured for the selected crop.', [
                'crop_id' => $input['crop_id'], 'crop_name' => $selection['crop_name'],
            ]);
        }
        $stageNames = $allStages->map(fn (CropStage $stage): ?string => $stage->translatedName())->all();
        $growthStages = $this->growthStages($input, $stages, $sowingDate, $maxOffset, $warnings, $sourceEligibility, $stageNames);
        $activities = $this->activities($input, $stages, $sowingDate, $maxOffset, $warnings, $sourceEligibility);
        $this->activityOverlaps($activities, $warnings);
        $starts = [...array_column($growthStages, 'start_date'), ...array_column($activities, 'start_date')];
        $ends = [...array_column($growthStages, 'end_date'), ...array_column($activities, 'end_date')];

        return [
            'input' => $input, 'selection' => $selection, 'growth_stages' => $growthStages, 'activities' => $activities,
            'summary' => [
                'total_growth_stages' => count($growthStages), 'total_activities' => count($activities),
                'first_date' => $starts === [] ? null : min($starts), 'last_date' => $ends === [] ? null : max($ends),
            ],
            'warnings' => array_values($warnings),
        ];
    }

    /** @param array<string, mixed> $input */
    private function matching(Builder $query, array $input): Builder
    {
        return $query->where('is_active', true)->where('season_id', $input['season_id'])
            ->where(function (Builder $query) use ($input): void {
                $query->whereNull('crop_variety_id');
                if ($input['crop_variety_id'] !== null) {
                    $query->orWhere('crop_variety_id', $input['crop_variety_id']);
                }
            })->where(fn (Builder $query): Builder => $query->where('region_scope', 'all')
            ->orWhere(fn (Builder $query): Builder => $query->where('region_scope', 'specific')
                ->whereHas('regions', fn (Builder $regions): Builder => $regions->where('regions.id', $input['district_id']))));
    }

    /** @return Closure(HasMany): HasMany */
    private function names(): Closure
    {
        return static fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default'), 'en', 'hi']))
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
    }

    /** @return array<int|string, mixed> */
    private function sourceRelations(): array
    {
        return [
            'source:id,title,publisher,source_type,source_url,reference_identifier,language,is_active,verification_status',
            'source.translations' => static fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))
                ->select(['id', 'knowledge_source_id', 'locale', 'title']),
            'source.attachments:id,knowledge_source_id,file_path',
        ];
    }

    /**
     * Exact variety + district > exact variety + all > generic + district > generic + all.
     * Equal-ranked identical windows are consensus duplicates, represented by the lowest ID
     * and all contributing IDs. Disagreeing top-ranked windows never produce an estimate.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<int, CropStage>  $stages
     * @param  array<string, array<string, mixed>>  $warnings
     * @param  array<int, string|null>  $sourceEligibility
     * @param  array<int, string|null>  $stageNames
     * @return list<array<string, mixed>>
     */
    private function growthStages(array $input, Collection $stages, CarbonImmutable $sowingDate, int $maxOffset, array &$warnings, array &$sourceEligibility, array $stageNames): array
    {
        $timelines = $this->matching(CropStageTimeline::query(), $input)
            ->whereHas('cropStage', fn (Builder $stage): Builder => $stage->where('crop_id', $input['crop_id']))
            ->with($this->sourceRelations())->orderBy('id')->get();
        $groups = [];
        foreach ($timelines as $timeline) {
            $timelineContext = ['stage_timeline_id' => $timeline->id, 'stage_timeline_name' => $stageNames[$timeline->crop_stage_id] ?? null];
            $reason = ! $stages->has($timeline->crop_stage_id) ? 'stage_ineligible' : $this->sourceReason($timeline->source, $sourceEligibility);
            if ($reason !== null) {
                $this->warning($warnings, 'STAGE_TIMELINE_INELIGIBLE', 'A matching stage timeline failed publication eligibility.', [...$timelineContext, 'reason' => $reason]);

                continue;
            }
            $range = $this->range($timeline, 'days_after_sowing_min', 'days_after_sowing_max', 4294967295);
            if ($range === null) {
                $this->warning($warnings, 'INVALID_STAGE_DAY_RANGE', 'A matching stage timeline has an invalid stored day range.', $timelineContext);

                continue;
            }
            $groups[$timeline->crop_stage_id][] = ['timeline' => $timeline, 'range' => $range,
                'rank' => ($timeline->crop_variety_id === null ? 0 : 2) + ($timeline->region_scope === 'specific' ? 1 : 0)];
        }
        $result = [];
        foreach ($stages as $stage) {
            $stageName = $this->name($stage, 'crop_stage', $warnings);
            $stageContext = ['crop_stage_id' => $stage->id, 'crop_stage_name' => $stageName];
            $matches = collect($groups[$stage->id] ?? []);
            if ($matches->isEmpty()) {
                $this->warning($warnings, 'STAGE_TIMELINE_NOT_FOUND', 'No eligible matching timeline was found for growth stage "'.$stageName.'".', $stageContext);

                continue;
            }
            $top = $matches->where('rank', $matches->max('rank'))->values();
            $ids = $top->map(fn (array $match): int => $match['timeline']->id)->all();
            $timelineContext = [...$stageContext, 'timeline_ids' => $ids, 'timeline_names' => array_fill(0, count($ids), $stageName)];
            if ($top->map(fn (array $match): string => implode(':', $match['range']))->unique()->count() > 1) {
                $this->warning($warnings, 'STAGE_TIMELINE_CONFLICT', 'Equally specific stage timelines disagree; no estimated window is returned for this stage.', $timelineContext);

                continue;
            }
            if (count($ids) > 1) {
                $this->warning($warnings, 'STAGE_TIMELINE_DUPLICATE', 'Equally specific timelines agree on the same window; all contributing IDs are retained.', $timelineContext);
            }
            $match = $top->first();
            $window = $this->window($sowingDate, $match['range'], $maxOffset, $warnings, $timelineContext);
            if ($window === null) {
                continue;
            }
            $timeline = $match['timeline'];
            $result[] = [
                'crop_stage_id' => $stage->id, 'stage_timeline_id' => $timeline->id, 'stage_timeline_ids' => $ids,
                'name' => $stageName, 'sequence' => $stage->sequence,
                ...$window, 'is_estimated' => true, 'source_id' => $timeline->source_id,
                'source_ids' => $top->map(fn (array $match): int => $match['timeline']->source_id)->unique()->values()->all(),
                'source' => $this->sourceSummary($timeline->source, $warnings),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  Collection<int, CropStage>  $stages
     * @param  array<string, array<string, mixed>>  $warnings
     * @param  array<int, string|null>  $sourceEligibility
     * @return list<array<string, mixed>>
     */
    private function activities(array $input, Collection $stages, CarbonImmutable $sowingDate, int $maxOffset, array &$warnings, array &$sourceEligibility): array
    {
        $templates = $this->matching(CropActivityTemplate::query(), $input)->where('crop_id', $input['crop_id'])
            ->with([
                ...$this->sourceRelations(), 'category.translations' => $this->names(),
                'translations' => static fn (HasMany $translations): HasMany => $translations
                    ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default'), ...CropActivityTemplate::REQUIRED_LOCALES])),
            ])->withExists(['translations as incomplete_publication_content' => fn (Builder $translations): Builder => $translations
            ->whereIn('locale', array_keys(config('languages.supported')))
            ->where(fn (Builder $query): Builder => $query->whereRaw("TRIM(title) = ''")->orWhereNull('instructions')->orWhereRaw("TRIM(instructions) = ''"))])
            ->orderBy('id')->get();
        $result = [];
        foreach ($templates->unique('id') as $template) {
            $translation = $template->translation();
            $templateContext = ['template_id' => $template->id, 'template_title' => $translation?->title];
            if ($template->schedule_type !== 'after_sowing') {
                $this->warning($warnings, 'UNSUPPORTED_SCHEDULE_TYPE', 'A matching activity uses an unsupported scheduling type.', [...$templateContext, 'schedule_type' => $template->schedule_type]);

                continue;
            }
            $category = $template->category;
            $reason = $category === null || ! $category->is_active || $category->activationErrors() !== [] ? 'category_ineligible' : null;
            if ($template->crop_stage_id !== null && ! $stages->has($template->crop_stage_id)) {
                $reason ??= 'stage_ineligible';
            }
            if (! in_array($template->priority, CropActivityTemplate::PRIORITIES, true)) {
                $reason ??= 'priority_invalid';
            }
            if ($template->incomplete_publication_content || ! $this->requiredTitles($template)) {
                $reason ??= 'content_incomplete';
            }
            $reason ??= $this->sourceReason($template->source, $sourceEligibility);
            if ($reason !== null) {
                $this->warning($warnings, 'ACTIVITY_TEMPLATE_INELIGIBLE', 'A matching activity template failed publication eligibility.', [...$templateContext, 'reason' => $reason]);

                continue;
            }
            $range = $this->range($template, 'start_offset_days', 'end_offset_days', CropActivityTemplate::MAX_OFFSET_DAYS);
            if ($range === null) {
                $this->warning($warnings, 'INVALID_ACTIVITY_DAY_RANGE', 'A matching activity has an invalid stored day range.', $templateContext);

                continue;
            }
            $window = $this->window($sowingDate, $range, $maxOffset, $warnings, $templateContext);
            if ($window === null) {
                continue;
            }
            $this->translationWarning($template, 'crop_activity_template', $warnings);
            $stage = $template->crop_stage_id === null ? null : $stages->get($template->crop_stage_id);
            $result[] = [
                'template_id' => $template->id, 'title' => $translation?->title,
                'description' => $translation?->description, 'instructions' => $translation?->instructions,
                'category_id' => $category->id, 'category_name' => $this->name($category, 'activity_category', $warnings),
                'crop_stage_id' => $template->crop_stage_id, 'crop_stage_name' => $stage === null ? null : $this->name($stage, 'crop_stage', $warnings),
                'schedule_type' => $template->schedule_type, ...$window, 'priority' => $template->priority,
                'source_id' => $template->source_id, 'source' => $this->sourceSummary($template->source, $warnings), 'status' => 'planned',
            ];
        }
        usort($result, static fn (array $left, array $right): int => [$left['start_date'], $left['end_date'], $left['template_id']] <=> [$right['start_date'], $right['end_date'], $right['template_id']]);
        if ($result === []) {
            $this->warning($warnings, 'ACTIVITY_TEMPLATES_NOT_FOUND', 'No eligible activity templates match the selected inputs.');
        }

        return $result;
    }

    /** @param array<int, string|null> $cache */
    private function sourceReason(?KnowledgeSource $source, array &$cache): ?string
    {
        if ($source === null) {
            return 'source_missing';
        }
        if (array_key_exists($source->id, $cache)) {
            return $cache[$source->id];
        }
        if (! $source->is_active || $source->verification_status !== 'verified') {
            return $cache[$source->id] = 'source_inactive_or_unverified';
        }
        try {
            return $cache[$source->id] = $source->hasUsableReference(attachments: $source->getRelation('attachments')) ? null : 'source_reference_missing';
        } catch (FilesystemException) {
            return $cache[$source->id] = 'source_reference_unavailable';
        }
    }

    /** @param list<string> $locales */
    private function requiredNames(Model $model, array $locales): bool
    {
        foreach ($locales as $locale) {
            if (blank($model->getRelation('translations')->firstWhere('locale', $locale)?->name)) {
                return false;
            }
        }

        return true;
    }

    private function requiredTitles(CropActivityTemplate $template): bool
    {
        foreach (CropActivityTemplate::REQUIRED_LOCALES as $locale) {
            if (blank($template->translations->firstWhere('locale', $locale)?->title)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{int, int}|null */
    private function range(Model $record, string $startField, string $endField, int $maximum): ?array
    {
        $start = filter_var($record->getRawOriginal($startField), FILTER_VALIDATE_INT);
        $end = filter_var($record->getRawOriginal($endField), FILTER_VALIDATE_INT);

        return $start !== false && $end !== false && $start >= 0 && $end >= $start && $end <= $maximum ? [$start, $end] : null;
    }

    /**
     * @param  array{int, int}  $range
     * @param  array<string, array<string, mixed>>  $warnings
     * @param  array<string, mixed>  $context
     * @return array{start_day: int, end_day: int, start_date: string, end_date: string}|null
     */
    private function window(CarbonImmutable $sowingDate, array $range, int $maximum, array &$warnings, array $context): ?array
    {
        if ($range[1] > $maximum) {
            $this->warning($warnings, 'DATE_RANGE_UNSUPPORTED', 'The configured offsets exceed the supported four-digit calendar date range for this sowing date.', $context);

            return null;
        }

        return ['start_day' => $range[0], 'end_day' => $range[1],
            'start_date' => $sowingDate->addDays($range[0])->toDateString(), 'end_date' => $sowingDate->addDays($range[1])->toDateString()];
    }

    /** @param array<string, array<string, mixed>> $warnings */
    private function name(Model $record, string $type, array &$warnings): ?string
    {
        $this->translationWarning($record, $type, $warnings);

        return $record->translatedName();
    }

    /** @param array<string, array<string, mixed>> $warnings */
    private function translationWarning(Model $record, string $type, array &$warnings): void
    {
        if ($record->getRelation('translations')->firstWhere('locale', app()->getLocale()) === null) {
            $label = $record instanceof CropActivityTemplate
                ? ['resource_title' => $record->translation()?->title]
                : ['resource_name' => $record->translatedName()];
            $this->warning($warnings, 'TRANSLATION_FALLBACK', 'Requested-language content is unavailable; the configured default translation is used when present.', [
                'resource_type' => $type, 'resource_id' => $record->id, ...$label,
                'requested_locale' => app()->getLocale(), 'fallback_locale' => config('languages.default'),
            ]);
        }
    }

    /** @param array<string, array<string, mixed>> $warnings @return array<string, mixed> */
    private function sourceSummary(KnowledgeSource $source, array &$warnings): array
    {
        if ($source->translations->firstWhere('locale', app()->getLocale()) === null && $source->language !== app()->getLocale()) {
            $this->warning($warnings, 'SOURCE_TITLE_FALLBACK', 'A translated source title is unavailable; its default translation or original citation title is shown.', [
                'source_id' => $source->id, 'source_title' => $source->translatedTitle(), 'requested_locale' => app()->getLocale(),
            ]);
        }

        return ['id' => $source->id, 'title' => $source->translatedTitle(), 'publisher' => $source->publisher,
            'source_type' => $source->source_type, 'source_url' => $source->source_url, 'reference_identifier' => $source->reference_identifier];
    }

    /**
     * Sweep matching intervals within category/stage scopes; generic stages intersect each stage.
     * Distinct tasks are retained. Warnings identify intersecting clusters, not every date-sharing pair.
     *
     * @param  list<array<string, mixed>>  $activities
     * @param  array<string, array<string, mixed>>  $warnings
     */
    private function activityOverlaps(array $activities, array &$warnings): void
    {
        foreach (collect($activities)->groupBy('category_id') as $categoryId => $categoryActivities) {
            $categoryName = $categoryActivities->first()['category_name'];
            $templateTitles = $categoryActivities->pluck('title', 'template_id');
            $generic = $categoryActivities->whereNull('crop_stage_id');
            $groups = $categoryActivities->whereNotNull('crop_stage_id')->groupBy('crop_stage_id')->map(fn (Collection $group): Collection => $group->concat($generic));
            $groups->push($generic);
            foreach ($groups as $group) {
                $cluster = [];
                $end = -1;
                $flush = function () use (&$cluster, &$warnings, $categoryId, $categoryName, $templateTitles): void {
                    if (count($cluster) > 1) {
                        sort($cluster);
                        $this->warning($warnings, 'POTENTIAL_ACTIVITY_OVERLAP', 'These distinct activities have intersecting applicability and scheduling windows. Review them together; no blocking template precedence policy is defined.', [
                            'category_id' => (int) $categoryId, 'category_name' => $categoryName,
                            'template_ids' => $cluster, 'template_titles' => array_map(fn (int $id): ?string => $templateTitles->get($id), $cluster),
                        ]);
                    }
                };
                foreach ($group->sortBy([['start_day', 'asc'], ['end_day', 'asc'], ['template_id', 'asc']]) as $activity) {
                    if ($activity['start_day'] > $end) {
                        $flush();
                        $cluster = [];
                        $end = -1;
                    }
                    $cluster[] = $activity['template_id'];
                    $end = max($end, $activity['end_day']);
                }
                $flush();
            }
        }
    }

    /** @param array<string, array<string, mixed>> $warnings @param array<string, mixed> $context */
    private function warning(array &$warnings, string $code, string $message, array $context = []): void
    {
        $warnings[$code.':'.json_encode($context, JSON_THROW_ON_ERROR)] = ['code' => $code, 'message' => $message, 'context' => (object) $context];
    }
}

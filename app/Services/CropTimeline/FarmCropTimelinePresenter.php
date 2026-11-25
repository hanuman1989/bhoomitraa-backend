<?php

namespace App\Services\CropTimeline;

use App\Models\CropActivityTemplate;
use App\Models\CropStage;
use App\Models\FarmCropActivity;
use App\Models\FarmCropCycle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the display-ready farmer crop timeline: ordered sections, derived time states,
 * counts and labels in the request locale. The frontend renders this without reformatting.
 */
class FarmCropTimelinePresenter
{
    /** Record types and the template flag that enables each. */
    private const RECORD_FLAGS = ['notes' => 'allow_notes', 'expenses' => 'allow_expenses', 'labour' => 'allow_labour', 'photos' => 'allow_attachments'];

    private const FLAGS = ['allow_status_update', 'allow_notes', 'allow_expenses', 'allow_labour', 'allow_attachments', 'allow_rescheduling'];

    private string $today;

    private string $locale;

    private string $otherLocale;

    public function __construct()
    {
        $this->today = CarbonImmutable::now(config('crop_timeline.timezone'))->toDateString();
        $this->locale = app()->getLocale();
        $this->otherLocale = collect(array_keys(config('languages.supported')))->first(fn (string $locale): bool => $locale !== $this->locale) ?? $this->locale;
    }

    /** One query per relation, regardless of the number of activities. */
    public function load(FarmCropCycle $cycle): FarmCropCycle
    {
        $cycle->load([
            'farm' => fn ($farm) => $farm->forApi(), 'crop.translations', 'cropVariety.translations', 'season.translations',
            'activities.template.translations', 'activities.template.category.translations',
            'activities.notes' => fn (HasMany $notes): HasMany => $notes->orderByDesc('note_date')->orderByDesc('id'),
        ]);

        return $cycle;
    }

    /** @return array<string, mixed> */
    public function timeline(FarmCropCycle $cycle): array
    {
        $this->load($cycle);
        $generated = $cycle->timeline_generated_at !== null;
        $activities = $cycle->activities;
        $stages = $this->stages($cycle, $activities);
        $sowingDay = $cycle->sowing_date === null ? null : $this->days($cycle->sowing_date, $this->today);
        $current = $sowingDay === null || $sowingDay < 0 ? null
            : $stages->first(fn (array $stage): bool => $stage['start_day'] !== null && $stage['start_day'] <= $sowingDay && $stage['end_day'] >= $sowingDay);

        $sections = [];
        foreach ($activities as $activity) {
            $section = $this->sectionFor($cycle, $activity, $stages);
            $sections[$section['key']] ??= $section;
            $sections[$section['key']]['items'][] = $activity;
        }
        $sections = collect($sections)->map(fn (array $section): array => $this->section($cycle, $section, $sowingDay, $current))
            ->sortBy(fn (array $section): array => $section['sort'])->values()
            ->map(fn (array $section): array => collect($section)->except('sort')->all())->all();

        return [
            'id' => $cycle->id, 'status' => $cycle->status, 'generated' => $generated,
            'can_generate' => ! $generated && $cycle->status === 'active', 'editable' => $cycle->status === 'active',
            'crop' => $this->crop($cycle, $sowingDay),
            'alert' => ['show' => $generated && $cycle->timeline_alert_seen_at === null],
            'stats' => $this->stats($activities, $sections),
            'current_stage_id' => $current['crop_stage_id'] ?? null,
            'sections' => $sections,
        ];
    }

    /**
     * The response for an activity change: the activity, the refreshed totals and its section progress.
     *
     * @return array<string, mixed>
     */
    public function mutation(FarmCropCycle $cycle, int $activityId): array
    {
        $timeline = $this->timeline($cycle);
        foreach ($timeline['sections'] as $section) {
            foreach ($section['activities'] as $activity) {
                if ($activity['id'] === $activityId) {
                    return ['activity' => $activity, 'stats' => $timeline['stats'], 'current_stage_id' => $timeline['current_stage_id'],
                        'section' => ['key' => $section['key'], 'done_count' => $section['done_count'], 'total_count' => $section['total_count']]];
                }
            }
        }

        abort(404);
    }

    /** @return Collection<int, array{crop_stage_id: int, sequence: int, start_day: ?int, end_day: ?int, stage: ?CropStage}> */
    private function stages(FarmCropCycle $cycle, Collection $activities): Collection
    {
        $windows = collect($cycle->planning_snapshot['timeline']['stages'] ?? [])->keyBy('crop_stage_id');
        $ids = $windows->keys()->merge($activities->pluck('template.crop_stage_id')->filter())->unique()->values();
        $models = $ids->isEmpty() ? collect() : CropStage::query()->whereIn('id', $ids)->with('translations')->get()->keyBy('id');

        return $ids->map(fn (int $id): array => [
            'crop_stage_id' => $id, 'sequence' => (int) ($windows[$id]['sequence'] ?? $models->get($id)?->sequence ?? PHP_INT_MAX),
            'start_day' => $windows[$id]['start_day'] ?? null, 'end_day' => $windows[$id]['end_day'] ?? null, 'stage' => $models->get($id),
        ])->sortBy([['sequence', 'asc'], ['crop_stage_id', 'asc']])->values();
    }

    /** Before-sowing and after-harvest activities are one section each; after-sowing activities group by growth stage. */
    private function sectionFor(FarmCropCycle $cycle, FarmCropActivity $activity, Collection $stages): array
    {
        $type = $activity->template->schedule_type;
        if ($type === 'before_sowing' || $type === 'after_harvesting') {
            $kind = $type === 'before_sowing' ? 'before_sowing' : 'after_harvest';

            return ['key' => $kind.':'.$activity->id, 'type' => $kind, 'stage' => null, 'items' => []];
        }
        $stageId = $activity->template->crop_stage_id;
        if ($stageId === null && $cycle->sowing_date !== null) {
            // Stage-less after-sowing activities join the growth stage whose window holds their planned start.
            $day = $this->days($cycle->sowing_date, $activity->planned_start_date);
            $stageId = ($stages->first(fn (array $stage): bool => $stage['start_day'] !== null && $stage['start_day'] <= $day && $stage['end_day'] >= $day)
                ?? $stages->filter(fn (array $stage): bool => $stage['start_day'] !== null && $stage['start_day'] <= $day)->last()
                ?? $stages->first())['crop_stage_id'] ?? null;
        }

        return ['key' => 'growth_stage:'.($stageId ?? 'none'), 'type' => 'growth_stage',
            'stage' => $stageId === null ? null : $stages->firstWhere('crop_stage_id', $stageId), 'items' => []];
    }

    /** @return array<string, mixed> */
    private function section(FarmCropCycle $cycle, array $section, ?int $sowingDay, ?array $current): array
    {
        $items = collect($section['items'])->sortBy([
            fn (FarmCropActivity $a, FarmCropActivity $b): int => $a->start_date->toDateString() <=> $b->start_date->toDateString(),
            fn (FarmCropActivity $a, FarmCropActivity $b): int => $a->end_date->toDateString() <=> $b->end_date->toDateString(),
            fn (FarmCropActivity $a, FarmCropActivity $b): int => $a->id <=> $b->id,
        ])->values();
        $tasks = $items->filter(fn (FarmCropActivity $activity): bool => $activity->tracksStatus());
        $icons = $items->map(fn (FarmCropActivity $activity): string => $this->categoryIcon($activity->template))->unique()->values();
        $stage = $section['stage'];
        $first = $items->first();
        $order = match ($section['type']) {
            'before_sowing' => [0, $first->start_date->toDateString(), 0],
            'growth_stage' => [1, str_pad((string) ($stage['sequence'] ?? PHP_INT_MAX), 20, '0', STR_PAD_LEFT), $stage['crop_stage_id'] ?? 0],
            default => [2, $first->start_date->toDateString(), 0],
        };

        return [
            'sort' => [...$order, $first->id],
            'key' => $section['key'], 'type' => $section['type'],
            'title' => match ($section['type']) {
                'before_sowing' => __('crop_timeline.sections.before_sowing'),
                'after_harvest' => __('crop_timeline.sections.after_harvest'),
                default => ($stage['stage'] ?? null)?->translatedName() ?? __('crop_timeline.sections.after_sowing'),
            },
            'other_title' => match ($section['type']) {
                'before_sowing' => __('crop_timeline.sections.before_sowing', [], $this->otherLocale),
                'after_harvest' => __('crop_timeline.sections.after_harvest', [], $this->otherLocale),
                default => $this->otherName($stage['stage'] ?? null) ?? __('crop_timeline.sections.after_sowing', [], $this->otherLocale),
            },
            'stage' => $stage === null ? null : $this->stage($cycle, $stage, $sowingDay, $current),
            'marker' => ['icon' => $icons->count() === 1 ? $icons->first() : 'MapLayersIcon',
                'icons' => $icons->count() > 1 ? $icons->take(3)->all() : [], 'more' => max(0, $icons->count() - 3)],
            'activity_count' => $items->count(),
            'done_count' => $tasks->where('status', 'completed')->count(), 'total_count' => $tasks->count(),
            'activities' => $items->map(fn (FarmCropActivity $activity): array => $this->activity($cycle, $activity, $section['key']))->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function stage(FarmCropCycle $cycle, array $stage, ?int $sowingDay, ?array $current): array
    {
        $hasWindow = $stage['start_day'] !== null && $cycle->sowing_date !== null;
        $range = null;
        if ($hasWindow) {
            $dates = $this->dateRange($cycle->sowing_date->addDays($stage['start_day']), $cycle->sowing_date->addDays($stage['end_day']));
            $range = $stage['start_day'] === $stage['end_day']
                ? __('crop_timeline.stage_single', ['day' => $stage['start_day'], 'dates' => $dates])
                : __('crop_timeline.stage_range', ['from' => $stage['start_day'], 'to' => $stage['end_day'], 'dates' => $dates]);
        }

        return [
            'id' => $stage['crop_stage_id'], 'sequence' => $stage['sequence'] === PHP_INT_MAX ? null : $stage['sequence'],
            'sequence_label' => $stage['sequence'] === PHP_INT_MAX ? null : __('crop_timeline.sections.growth_stage', ['number' => str_pad((string) $stage['sequence'], 2, '0', STR_PAD_LEFT)]),
            'range_label' => $range,
            'state' => ($current['crop_stage_id'] ?? null) === $stage['crop_stage_id'] ? 'current'
                : ($hasWindow && $sowingDay !== null && $stage['end_day'] < $sowingDay ? 'past' : 'future'),
        ];
    }

    /** @return array<string, mixed> */
    private function activity(FarmCropCycle $cycle, FarmCropActivity $activity, string $sectionKey): array
    {
        $template = $activity->template;
        $translation = $template->translation($this->locale);
        $other = $template->translations->firstWhere('locale', $this->otherLocale);
        $state = $activity->timeState($this->today);
        $tracks = $activity->tracksStatus();
        $flags = collect(self::FLAGS)->mapWithKeys(fn (string $flag): array => [$flag => (bool) $template->{$flag}])->all();
        $records = ['notes' => $activity->notes->count(), 'expenses' => 0, 'labour' => 0, 'photos' => 0];
        $smartActions = collect($template->smart_actions ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && array_key_exists($key, config('crop_timeline.smart_actions')))
            ->values()->map(fn (string $key): array => [
                'key' => $key, 'label' => __("crop_timeline.smart_actions.$key.label"),
                'description' => __("crop_timeline.smart_actions.$key.description"),
                'icon' => config("crop_timeline.smart_actions.$key.icon"), 'tone' => config("crop_timeline.smart_actions.$key.tone"),
                // Smart action usage is not tracked yet; the shape is final.
                'used_at_label' => null,
            ]);
        $category = $template->category;
        $categoryIcon = $this->categoryIcon($template);

        return [
            'id' => $activity->id, 'section_key' => $sectionKey,
            'title' => $translation?->title, 'other_title' => $other?->title !== $translation?->title ? $other?->title : null,
            'description' => $translation?->description, 'instructions' => $translation?->instructions,
            'category' => $category === null ? null : ['id' => $category->id, 'name' => $category->translatedName(),
                'icon' => $categoryIcon, 'tone' => config("crop_timeline.icon_tones.$categoryIcon", config('crop_timeline.default_tone'))],
            'type' => $template->activity_type, 'type_label' => __('crop_timeline.types.'.$template->activity_type),
            'priority' => $template->priority, 'priority_label' => __('crop_timeline.priorities.'.$template->priority),
            'schedule_type' => $template->schedule_type, 'schedule_label' => $this->scheduleLabel($cycle, $activity),
            'when_label' => $this->whenLabel($cycle, $activity, $state),
            'when_tone' => in_array($state, ['due', 'late'], true) ? $state : null,
            'time_state' => $state,
            'start_date' => $activity->start_date->toDateString(), 'start_date_label' => $this->date($activity->start_date),
            'end_date' => $activity->end_date->toDateString(), 'end_date_label' => $this->date($activity->end_date),
            // Calendar list grouping: the month the activity starts in.
            'month_key' => $activity->start_date->format('Y-m'),
            'month_label' => $this->asDate($activity->start_date)->locale($this->locale)->translatedFormat('F Y'),
            'planned_start_date' => $activity->planned_start_date->toDateString(), 'planned_end_date' => $activity->planned_end_date->toDateString(),
            'planned_range_label' => $this->dateRange($activity->planned_start_date, $activity->planned_end_date),
            'is_rescheduled' => $activity->isRescheduled(),
            'last_tile' => $this->lastTile($activity, $state),
            'status' => $tracks ? $activity->status : null,
            'status_label' => $tracks ? __('crop_timeline.statuses.'.$activity->status) : null,
            'status_line' => $tracks ? $this->statusLine($activity) : null,
            'started_at_label' => $activity->started_at === null ? null : $this->date($this->local($activity->started_at), false),
            'completed_at_label' => $activity->completed_at === null ? null : $this->date($this->local($activity->completed_at)),
            'flags' => $flags,
            'records' => $records, 'records_total' => array_sum($records),
            'record_types_remaining' => collect(self::RECORD_FLAGS)->filter(fn (string $flag, string $type): bool => $flags[$flag] && $records[$type] === 0)->count(),
            'notes' => $activity->notes->map(fn ($note): array => ['id' => $note->id, 'date' => $note->note_date->toDateString(),
                'date_label' => $this->date($note->note_date, false), 'text' => $note->note])->values()->all(),
            'smart_actions' => $smartActions->all(),
            'tools_remaining' => $smartActions->whereNull('used_at_label')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function crop(FarmCropCycle $cycle, ?int $sowingDay): array
    {
        $farm = $cycle->farm;
        $unit = config("area_units.units.{$cycle->area_unit}");
        $location = collect($farm?->regionContext()['applicable_regions'] ?? [])
            ->reject(fn (array $region): bool => $region['region_type'] === 'country')->pluck('name');
        $harvestMin = $cycle->expected_harvest_date_min;
        $harvestMax = $cycle->expected_harvest_date_max;

        return [
            'name' => $cycle->crop?->translatedName(), 'other_name' => $this->otherName($cycle->crop),
            'image' => $cycle->crop?->image === null ? null : Storage::disk(config('filesystems.crop_images_disk'))->url($cycle->crop->image),
            'variety' => $cycle->cropVariety?->translatedName(), 'season' => $cycle->season?->translatedName(),
            'farm' => $farm?->name,
            'area_label' => $cycle->cultivated_area === null ? null
                : rtrim(rtrim((string) $cycle->cultivated_area, '0'), '.').' '.($unit === null ? $cycle->area_unit : ($this->locale === 'hi' ? $unit['label_hi'] : $unit['label'])),
            'location' => collect([$farm?->village, ...$location])->filter()->unique()->implode(', ') ?: null,
            'sowing_date' => $cycle->sowing_date?->toDateString(),
            'sowing_date_label' => $cycle->sowing_date === null ? null : $this->date($cycle->sowing_date),
            'expected_harvest_label' => $harvestMin === null ? null : $this->dateRange($harvestMin, $harvestMax ?? $harvestMin),
            'days' => $sowingDay === null ? null : [
                'label' => __($sowingDay < 0 ? 'crop_timeline.crop.days_to_sowing' : 'crop_timeline.crop.days_after_sowing'),
                'value' => match (true) {
                    $sowingDay < 0 => (string) -$sowingDay,
                    $sowingDay === 0 => __('crop_timeline.crop.sowing_day'),
                    default => __('crop_timeline.crop.day_number', ['day' => $sowingDay]),
                },
            ],
        ];
    }

    /** @param  list<array<string, mixed>>  $sections @return array<string, int> */
    private function stats(Collection $activities, array $sections): array
    {
        $tasks = $activities->filter(fn (FarmCropActivity $activity): bool => $activity->tracksStatus());

        return [
            'growth_stages' => collect($sections)->where('type', 'growth_stage')->whereNotNull('stage')->count(),
            'activities' => $activities->count(),
            'due_now' => $tasks->filter(fn (FarmCropActivity $activity): bool => $activity->timeState($this->today) === 'due')->count(),
            'late' => $tasks->filter(fn (FarmCropActivity $activity): bool => $activity->timeState($this->today) === 'late')->count(),
            'completed' => $tasks->where('status', 'completed')->count(),
            'total_tasks' => $tasks->count(),
        ];
    }

    private function scheduleLabel(FarmCropCycle $cycle, FarmCropActivity $activity): string
    {
        $type = $activity->template->schedule_type;
        if ($type === 'after_sowing' && $cycle->sowing_date !== null
            && $activity->planned_start_date->equalTo($cycle->sowing_date) && $activity->planned_end_date->equalTo($cycle->sowing_date)) {
            return __('crop_timeline.schedule.sowing_day');
        }

        return __('crop_timeline.schedule.'.($type ?? 'after_sowing'));
    }

    /** e.g. "10–2 days before sowing · 18 – 26 Nov 2026 · 2 days left". */
    private function whenLabel(FarmCropCycle $cycle, FarmCropActivity $activity, string $state): string
    {
        $parts = [$this->offsetLabel($cycle, $activity), $this->dateRange($activity->start_date, $activity->end_date)];
        $start = $activity->start_date->toDateString();
        $end = $activity->end_date->toDateString();
        if ($state === 'due') {
            $left = $this->days($this->today, $end);
            $parts[] = $left === 0 ? __('crop_timeline.when.last_day') : trans_choice('crop_timeline.when.days_left', $left);
        } elseif ($state === 'upcoming') {
            $in = $this->days($this->today, $start);
            if ($in <= 7) {
                $parts[] = $in === 1 ? __('crop_timeline.when.starts_tomorrow') : __('crop_timeline.when.starts_in', ['count' => $in]);
            }
        } elseif ($state === 'late') {
            $parts[] = trans_choice('crop_timeline.when.ended_ago', $this->days($end, $this->today));
        }

        return implode(' · ', $parts);
    }

    private function offsetLabel(FarmCropCycle $cycle, FarmCropActivity $activity): string
    {
        if ($activity->isRescheduled()) {
            return __('crop_timeline.when.rescheduled');
        }
        $type = $activity->template->schedule_type;
        $anchor = $type === 'after_harvesting' && $cycle->expected_harvest_date_min !== null ? $cycle->expected_harvest_date_min : $cycle->sowing_date;
        if ($anchor === null) {
            return __('crop_timeline.schedule.'.$type);
        }
        if ($type === 'before_sowing') {
            [$from, $to] = [$this->days($activity->planned_start_date, $anchor), $this->days($activity->planned_end_date, $anchor)];
            $prefix = 'before';
        } else {
            [$from, $to] = [$this->days($anchor, $activity->planned_start_date), $this->days($anchor, $activity->planned_end_date)];
            $prefix = $type === 'after_harvesting' && $cycle->expected_harvest_date_min !== null ? 'harvest' : 'after';
        }
        if ($from === 0 && $to === 0) {
            return __($prefix === 'harvest' ? 'crop_timeline.when.harvest_day' : 'crop_timeline.when.sowing_day');
        }

        return $from === $to ? trans_choice("crop_timeline.when.{$prefix}_single", $from)
            : __("crop_timeline.when.{$prefix}_range", ['from' => $from, 'to' => $to]);
    }

    /** @return array{key: string, label: string, value: string} */
    private function lastTile(FarmCropActivity $activity, string $state): array
    {
        $end = $activity->end_date->toDateString();

        return match ($state) {
            'done' => ['key' => 'completed_on', 'label' => __('crop_timeline.tiles.completed_on'),
                'value' => $activity->completed_at === null ? '' : $this->date($this->local($activity->completed_at))],
            'due' => ['key' => 'time_left', 'label' => __('crop_timeline.tiles.time_left'),
                'value' => ($left = $this->days($this->today, $end)) === 0 ? __('crop_timeline.tiles.last_day') : trans_choice('crop_timeline.tiles.days', $left)],
            'late' => ['key' => 'window_ended', 'label' => __('crop_timeline.tiles.window_ended'),
                'value' => trans_choice('crop_timeline.tiles.days_ago', $this->days($end, $this->today))],
            'upcoming' => ['key' => 'starts_in', 'label' => __('crop_timeline.tiles.starts_in'),
                'value' => trans_choice('crop_timeline.tiles.days', $this->days($this->today, $activity->start_date->toDateString()))],
            default => ['key' => 'window', 'label' => __('crop_timeline.tiles.window'), 'value' => __('crop_timeline.tiles.ended')],
        };
    }

    private function statusLine(FarmCropActivity $activity): string
    {
        return match ($activity->status) {
            'completed' => __('crop_timeline.status_lines.completed', ['date' => $activity->completed_at === null ? '' : $this->date($this->local($activity->completed_at))]),
            'in_progress' => $activity->started_at === null ? __('crop_timeline.status_lines.in_progress')
                : __('crop_timeline.status_lines.in_progress_since', ['date' => $this->date($this->local($activity->started_at), false)]),
            default => __('crop_timeline.status_lines.pending'),
        };
    }

    private function categoryIcon(CropActivityTemplate $template): string
    {
        return $template->category?->icon ?: 'SproutIcon';
    }

    private function otherName(mixed $model): ?string
    {
        if ($model === null || ! $model->relationLoaded('translations') || $this->otherLocale === $this->locale) {
            return null;
        }
        $name = $model->translations->firstWhere('locale', $this->otherLocale)?->name;

        return $name !== null && $name !== $model->translatedName() ? $name : null;
    }

    /** Signed whole days from $from to $to (date-only). */
    private function days(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        return (int) round($this->asDate($from)->diffInDays($this->asDate($to), false));
    }

    private function asDate(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date instanceof CarbonInterface ? $date->toDateString() : $date, 'UTC');
    }

    /** A stored UTC timestamp as a date in the timeline timezone. */
    private function local(CarbonInterface $moment): CarbonImmutable
    {
        return $this->asDate(CarbonImmutable::instance($moment)->setTimezone(config('crop_timeline.timezone'))->toDateString());
    }

    private function date(CarbonInterface $date, bool $year = true): string
    {
        return $this->asDate($date)->locale($this->locale)->translatedFormat($year ? 'j M Y' : 'j M');
    }

    private function dateRange(CarbonInterface $start, CarbonInterface $end): string
    {
        if ($start->toDateString() === $end->toDateString()) {
            return $this->date($start);
        }

        return ($start->year === $end->year ? $this->date($start, false) : $this->date($start)).' – '.$this->date($end);
    }
}

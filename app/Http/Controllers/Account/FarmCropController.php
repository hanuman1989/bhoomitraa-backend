<?php

namespace App\Http\Controllers\Account;

use App\Helpers\AreaHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Account\FarmCropResource;
use App\Http\Resources\FarmResource;
use App\Models\Crop;
use App\Models\CropVariety;
use App\Models\Farm;
use App\Models\FarmCropCycle;
use App\Models\FarmCropEditDraft;
use App\Models\Fertilizer;
use App\Models\Season;
use App\Models\Seed;
use App\Models\SoilTest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class FarmCropController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $this->listFilters($request);
        $items = $this->listQuery($request, $filters)->withBoundary()->with($this->cardRelationships())
            ->orderBy('farm_crop_cycles.'.($filters['sort_by'] ?? 'created_at'), $filters['sort_direction'] ?? 'desc')
            ->orderByDesc('farm_crop_cycles.id')->paginate($request->integer('per_page', 15))->appends($filters);
        foreach ($items as $cycle) {
            $cycle->setAttribute('calculated_area_sqm', $cycle->planning_snapshot['boundary']['square_metres'] ?? AreaHelper::toSquareMeters($cycle->cultivated_area, $cycle->area_unit));
        }

        return response()->json(['status' => true, 'data' => FarmCropResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Crops retrieved successfully.']);
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->listFilters($request);
        $cycles = $this->listQuery($request, $filters)->get(['status', 'cultivated_area', 'area_unit', 'planning_snapshot']);
        $unit = AreaHelper::canonicalUnit($request->user('user')->farmerProfile()->value('preferred_units')) ?? config('area_units.default_unit');
        $area = BigDecimal::zero();
        $unresolved = 0;
        foreach ($cycles->where('status', 'active') as $cycle) {
            $sqm = $cycle->planning_snapshot['boundary']['square_metres'] ?? AreaHelper::toSquareMeters($cycle->cultivated_area, $cycle->area_unit);
            if ($sqm === null) {
                $unresolved++;
            } else {
                $area = $area->plus($sqm);
            }
        }

        return response()->json(['status' => true, 'data' => [
            'total_crops' => $cycles->count(), 'active_crops' => $cycles->where('status', 'active')->count(),
            'draft_crops' => $cycles->where('status', 'draft')->count(), 'completed_crops' => $cycles->where('status', 'completed')->count(),
            'cancelled_crops' => $cycles->where('status', 'cancelled')->count(),
            'total_cultivated_area' => $unresolved === 0 ? AreaHelper::fromSquareMeters((string) $area, $unit) : null,
            'total_cultivated_area_unit' => $unit, 'measurement_complete' => $unresolved === 0, 'unresolved_crops_count' => $unresolved,
        ], 'pagination' => null, 'message' => 'Crop summary retrieved successfully.']);
    }

    public function getMapList(Request $request): JsonResponse
    {
        $filters = $this->listFilters($request);
        $filters['status'] ??= 'active';
        $cycles = $this->listQuery($request, $filters)->withBoundary()->with(['crop.translations', 'cropVariety.translations'])->orderBy('farm_crop_cycles.id')->get();
        $data = $cycles->map(fn (FarmCropCycle $cycle): array => ['id' => $cycle->id, 'farm_id' => $cycle->farm_id, 'crop_id' => $cycle->crop_id,
            'crop_name' => $cycle->crop?->translatedName(), 'crop_variety_name' => $cycle->cropVariety?->translatedName(),
            'crop_color' => $cycle->crop?->crop_color, 'status' => $cycle->status, 'boundary' => $cycle->boundary_geojson,
            'latitude' => $cycle->latitude, 'longitude' => $cycle->longitude, 'cultivated_area' => $cycle->cultivated_area, 'area_unit' => $cycle->area_unit]);

        return response()->json(['status' => true, 'data' => $data, 'pagination' => null, 'message' => 'Crop map retrieved successfully.']);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $cycle = $this->ownedCycle($request, $id)->workingCopy();

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Crop retrieved successfully.']);
    }

    public function saveStepOne(Request $request): JsonResponse
    {
        $input = $request->validate($this->selectionRules());
        $key = $request->header('Idempotency-Key', $request->input('idempotency_key'));
        validator(['idempotency_key' => $key], ['idempotency_key' => ['required', 'uuid']])->validate();
        $selection = $this->selection($input);
        $fingerprint = hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
        $creationKey = hash('sha256', $key);
        $cycle = DB::transaction(function () use ($request, $selection, $fingerprint, $creationKey): FarmCropCycle {
            $user = User::query()->lockForUpdate()->findOrFail($request->user('user')->id);
            $existing = FarmCropCycle::query()->ownedBy($user->id)->where('created_by', $user->id)->where('creation_key', $creationKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->creation_payload_hash, $fingerprint), 409, 'This Idempotency-Key was used with a different crop selection.');

                return $existing;
            }
            $farm = $user->farms()->lockForUpdate()->findOrFail($selection['farm_id']);
            $this->validateSelection($selection, $farm);
            $cycle = $farm->cropCycles()->make(Arr::except($selection, ['farm_id']));
            $maturity = $this->harvestEstimate($cycle);
            $cycle->fill(Arr::only($maturity, FarmCropCycle::HARVEST_FIELDS));
            $progress = FarmCropCycle::normalizeProgress(['crop_selection' => 'completed'], false);
            $context = $farm->regionContext();
            $cycle->forceFill(['created_by' => $user->id, 'creation_key' => $creationKey, 'creation_payload_hash' => $fingerprint,
                'status' => 'draft', 'completed_step' => 1, 'revision' => 1, 'wizard_progress' => $progress,
                'planning_snapshot' => ['region_context' => $context, 'context_fingerprint' => $this->contextFingerprint($farm), 'maturity' => $maturity]])->save();

            return $cycle;
        }, attempts: 3);

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($this->ownedCycle($request, (string) $cycle->id)->workingCopy())),
            'pagination' => null, 'message' => 'Crop draft saved successfully.'], 201);
    }

    public function updateStepOne(Request $request, string $id): JsonResponse
    {
        $input = $request->validate($this->selectionRules() + ['revision' => ['required', 'integer', 'min:1']]);
        $cycle = $this->mutate($request, $id, function (FarmCropCycle $cycle, array &$state) use ($input, $request): void {
            $farm = $request->user('user')->farms()->forApi(true)->findOrFail($input['farm_id']);
            $selection = $this->selection($input);
            $this->validateSelection($selection, $farm);
            $farmChanged = (int) $cycle->farm_id !== $selection['farm_id'];
            $cycle->fill($selection);
            $cycle->setRelation('farm', $farm);
            if ($farmChanged) {
                // A boundary belongs to one farm; the farmer must select it again on the new farm.
                $cycle->fill(array_fill_keys(FarmCropCycle::BOUNDARY_FIELDS, null));
                $state['boundary'] = null;
                $state['planning_snapshot']['boundary'] = null;
                $this->invalidate($state, ['boundary']);
            }
            // Crop, variety or sowing date may have changed: refresh the informational harvest estimate.
            $maturity = $this->harvestEstimate($cycle);
            $cycle->fill(Arr::only($maturity, FarmCropCycle::HARVEST_FIELDS));
            $state['planning_snapshot']['maturity'] = $maturity;
            $state['planning_snapshot']['region_context'] = $farm->regionContext();
            $state['planning_snapshot']['context_fingerprint'] = $this->contextFingerprint($farm);
            $state['wizard_progress']['crop_selection'] = 'completed';
        }, (int) $input['farm_id']);

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Crop selection saved successfully.']);
    }

    public function saveStepTwo(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1'],
            'boundary_source' => ['required', Rule::in(config('farm_crops.boundary_sources'))],
            'boundary' => ['nullable', 'array:type,coordinates'],
            'cultivated_area' => ['required_if:boundary_source,manual_area', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99999999', 'decimal:0,8'],
            'area_unit' => ['required_if:boundary_source,manual_area', 'sometimes', Rule::in(AreaHelper::supportedUnits())],
            'gps_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2']]);
        $cycle = $this->mutate($request, $id, function (FarmCropCycle $cycle, array &$state) use ($input): void {
            $this->requireSteps($state, ['crop_selection']);
            $farm = $cycle->farm;
            $source = $input['boundary_source'];
            $boundary = $source === 'farm_boundary' ? $farm->boundary_geojson : ($input['boundary'] ?? null);
            if ($source !== 'manual_area' && $boundary === null) {
                throw ValidationException::withMessages(['boundary' => ['A saved farm boundary or a reviewed crop boundary is required.']]);
            }
            if ($source === 'manual_area') {
                if ($farm->has_boundary) {
                    throw ValidationException::withMessages(['boundary_source' => ['Select a spatial crop boundary for this mapped farm.']]);
                }
                $boundary = null;
                $metrics = null;
                $cycle->fill(['cultivated_area' => $input['cultivated_area'], 'area_unit' => $input['area_unit'],
                    'latitude' => $farm->latitude, 'longitude' => $farm->longitude, 'gps_accuracy_m' => null]);
            } else {
                $boundary = FarmCropCycle::normalizeBoundary($boundary);
                $metrics = Farm::boundaryMetrics($boundary);
                $unit = $input['area_unit'] ?? AreaHelper::canonicalUnit($farm->area_unit) ?? config('area_units.default_unit');
                $area = AreaHelper::fromSquareMeters($metrics['square_metres'], $unit);
                $cycle->fill(['cultivated_area' => $this->decimal($area, 8), 'area_unit' => $unit,
                    'latitude' => $metrics['latitude'], 'longitude' => $metrics['longitude'],
                    'gps_accuracy_m' => $source === 'gps' ? ($input['gps_accuracy_m'] ?? null) : null]);
            }
            $cycle->boundary_source = $source;
            $this->validateAllocation($farm, $boundary, $cycle->cultivated_area, $cycle->area_unit, $cycle->id);
            $state['boundary'] = $boundary;
            $state['planning_snapshot']['boundary'] = $metrics ?? ['square_metres' => AreaHelper::toSquareMeters($cycle->cultivated_area, $cycle->area_unit)];
            $state['wizard_progress']['boundary'] = 'completed';
        });

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Crop boundary saved successfully.']);
    }

    public function getFarmCropBoundaries(Request $request, string $farmId): JsonResponse
    {
        $input = $request->validate(['exclude_crop_id' => ['sometimes', 'integer', 'min:1']]);
        $farm = $request->user('user')->farms()->forApi(true)->findOrFail($farmId);
        if (isset($input['exclude_crop_id'])) {
            $farm->cropCycles()->findOrFail($input['exclude_crop_id']);
        }

        return response()->json(['status' => true, 'data' => $this->boundaryOverview($farm, $input['exclude_crop_id'] ?? null),
            'pagination' => null, 'message' => 'Farm crop boundaries retrieved successfully.']);
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $cycle = $this->ownedCycle($request, $id)->workingCopy();

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)),
            'pagination' => null, 'message' => 'Crop review retrieved successfully.']);
    }

    public function finalize(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $id, $input): void {
            $live = $this->lockCycle($request, $id);
            $live->load('editDraft');
            if ($live->status === 'active' && $live->editDraft === null) {
                abort_unless(in_array((int) $input['revision'], [$live->revision, $live->planning_snapshot['finalize_request_revision'] ?? null], true),
                    409, 'The crop changed. Reload the current revision.');

                return;
            }
            abort_unless(in_array($live->status, ['draft', 'active'], true), 409, 'This crop can no longer be activated.');
            $cycle = $live->workingCopy();
            abort_unless((int) $input['revision'] === $cycle->revision, 409, 'The crop changed. Reload the current revision.');
            if ($live->editDraft !== null) {
                abort_unless($live->editDraft->base_revision === $live->revision, 409, 'The active crop changed after this edit began.');
            }
            $state = $this->state($cycle);
            $issues = $this->validationIssues($cycle, $state, false);
            if ($issues !== []) {
                throw ValidationException::withMessages($issues);
            }
            $this->validateSelection($cycle->only(FarmCropCycle::SELECTION_FIELDS), $cycle->farm);
            $this->validateAllocation($cycle->farm, $state['boundary'], $cycle->cultivated_area, $cycle->area_unit, $cycle->id);
            if ($live->status === 'active') {
                $history = $state['planning_snapshot']['history'] ?? [];
                $history[] = Arr::except($live->planning_snapshot ?? [], ['history']);
                $state['planning_snapshot']['history'] = $history;
            }
            // The wizard no longer plans seed, soil, fertilizer or yield, so it creates no records in those tables.
            $maturity = $this->harvestEstimate($cycle);
            $cycle->fill(Arr::only($maturity, FarmCropCycle::HARVEST_FIELDS));
            $state['planning_snapshot']['maturity'] = $maturity;
            $state['planning_snapshot']['finalize_request_revision'] = (int) $input['revision'];
            $state['planning_snapshot']['accepted_at'] = now()->toISOString();
            $state['planning_snapshot']['region_context'] = $cycle->farm->regionContext();
            $state['planning_snapshot']['context_fingerprint'] = $this->contextFingerprint($cycle->farm);
            $state['wizard_progress']['review_save'] = 'completed';
            $cycle->status = 'active';
            $cycle->finalized_at = now();
            $cycle->revision++;
            $cycle->completed_step = count(FarmCropCycle::STEPS);
            $this->persistState($live, $cycle, $state);
            $live->editDraft()->delete();
        }, attempts: 3);

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($this->ownedCycle($request, $id))),
            'pagination' => null, 'message' => 'Crop saved successfully.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $files = DB::transaction(function () use ($request, $id): array {
            $cycle = $this->lockCycle($request, $id);
            if ($cycle->status !== 'draft') {
                abort_unless($cycle->status === 'active', 409, 'Historical crops cannot be deleted.');
                $pendingReport = $cycle->editDraft?->draft_data['soil_test']['report_file'] ?? null;
                $pendingOnly = $pendingReport !== null && ! $cycle->soilTests()->where('report_file', $pendingReport)->exists();
                $cycle->forceFill(['status' => 'cancelled', 'completion_reason' => 'farmer_cancelled', 'completed_at' => now(), 'revision' => $cycle->revision + 1])->save();
                $cycle->editDraft()->delete();

                return $pendingOnly ? [$pendingReport] : [];
            }
            $files = $cycle->soilTests()->whereNotNull('report_file')->pluck('report_file')->all();
            $cycle->soilTests()->delete();
            $cycle->delete();

            return $files;
        }, attempts: 3);
        DB::afterCommit(function () use ($files): void {
            foreach ($files as $path) {
                $this->deleteReport($path);
            }
        });

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Crop deleted or cancelled successfully.']);
    }

    public function discardEdit(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $path = DB::transaction(function () use ($request, $id, $input): ?string {
            $live = $this->lockCycle($request, $id);
            $edit = $live->editDraft()->first();
            abort_unless($edit !== null && $edit->revision === (int) $input['revision'], 409, 'Reload the current edit revision.');
            $path = $edit->draft_data['soil_test']['report_file'] ?? null;
            $historical = $path !== null && $live->soilTests()->where('report_file', $path)->exists();
            $edit->delete();
            $live->forceFill(['revision' => $edit->revision + 1])->save();

            return $historical ? null : $path;
        }, attempts: 3);
        if ($path !== null) {
            DB::afterCommit(fn () => $this->deleteReport($path));
        }

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($this->ownedCycle($request, $id))), 'pagination' => null, 'message' => 'Pending crop edit discarded successfully.']);
    }

    public function soilReport(Request $request, string $id, string $soilTest): StreamedResponse
    {
        $cycle = $this->ownedCycle($request, $id);
        $test = $cycle->soilTests()->findOrFail($soilTest);
        abort_if($test->report_file === null || ! Storage::disk(config('farm_crops.soil_reports_disk'))->exists($test->report_file), 404, 'Soil report not found.');

        return Storage::disk(config('farm_crops.soil_reports_disk'))->download($test->report_file, basename($test->report_file), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private function listFilters(Request $request): array
    {
        return $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'farm_id' => ['sometimes', 'integer', 'min:1'], 'crop_id' => ['sometimes', 'integer', 'min:1'],
            'crop_variety_id' => ['sometimes', 'integer', 'min:1'], 'season_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['draft', 'active', 'completed', 'cancelled'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['id', 'created_at', 'sowing_date', 'expected_harvest_date', 'status'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])]]);
    }

    private function listQuery(Request $request, array $filters): Builder
    {
        $query = FarmCropCycle::query()->ownedBy((int) $request->user('user')->id);
        foreach (['farm_id', 'crop_id', 'crop_variety_id', 'season_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where('farm_crop_cycles.'.$field, $filters[$field]);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('farm', fn (Builder $farm): Builder => $farm->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                foreach (['crop', 'cropVariety', 'season'] as $relation) {
                    $query->orWhereHas($relation.'.translations', fn (Builder $translation): Builder => $translation
                        ->whereIn('locale', ['en', 'hi'])->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                }
            });
        }

        return $query;
    }

    /** @return array<string|int, mixed> */
    private function cardRelationships(): array
    {
        return ['farm' => fn ($farm) => $farm->forApi(), 'crop.translations', 'crop.category.translations', 'cropVariety.translations', 'season.translations'];
    }

    private function ownedCycle(Request $request, string $id): FarmCropCycle
    {
        return FarmCropCycle::query()->ownedBy((int) $request->user('user')->id)->withBoundary()->with([
            'farm' => fn ($farm) => $farm->forApi(true), 'editDraft',
        ])->findOrFail($id);
    }

    /** All occupancy writers lock farm IDs in ascending order before the crop or edit row. */
    private function lockCycle(Request $request, string $id, ?int $targetFarmId = null): FarmCropCycle
    {
        $record = $this->ownedCycle($request, $id);
        $ids = array_values(array_unique(array_filter([$record->farm_id, $record->editDraft?->draft_data['attributes']['farm_id'] ?? null, $targetFarmId])));
        sort($ids);
        $farms = $request->user('user')->farms()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($farms->count() === count($ids), 404, 'Farm not found.');
        $live = FarmCropCycle::query()->ownedBy((int) $request->user('user')->id)->withBoundary()->lockForUpdate()->findOrFail($id);
        $edit = $live->editDraft()->lockForUpdate()->first();
        $live->setRelation('editDraft', $edit);
        abort_unless(in_array($live->farm_id, $ids, true) && ($edit === null || in_array((int) $edit->draft_data['attributes']['farm_id'], $ids, true)),
            409, 'The crop farm changed. Reload the current crop.');
        $live->setRelation('farm', $request->user('user')->farms()->forApi(true)->lockForUpdate()->findOrFail($live->farm_id));

        return $live;
    }

    private function mutate(Request $request, string $id, Closure $save, ?int $targetFarmId = null): FarmCropCycle
    {
        return DB::transaction(function () use ($request, $id, $save, $targetFarmId): FarmCropCycle {
            $live = $this->lockCycle($request, $id, $targetFarmId);
            abort_unless(in_array($live->status, ['draft', 'active'], true), 409, 'Historical crops cannot be edited.');
            $cycle = $live->workingCopy();
            abort_unless((int) $request->input('revision') === $cycle->revision, 409, 'The crop changed. Reload the current revision.');
            if ($live->editDraft !== null) {
                abort_unless($live->editDraft->base_revision === $live->revision, 409, 'The live crop changed after this edit began.');
            }
            $state = $this->state($cycle);
            $save($cycle, $state);
            // Any change must be reviewed and saved again on the Review & Save step.
            $state['wizard_progress']['review_save'] = 'pending';
            $cycle->revision++;
            $cycle->wizard_progress = $state['wizard_progress'];
            $cycle->completed_step = $cycle->completedSteps();
            $state['completed_step'] = $cycle->completed_step;
            $state['planning_snapshot']['region_context'] = $cycle->farm->regionContext();
            $state['planning_snapshot']['context_fingerprint'] = $this->contextFingerprint($cycle->farm);
            if ($live->status === 'active') {
                $state['attributes'] = $cycle->only($cycle->getFillable());
                $live->editDraft()->updateOrCreate([], ['draft_data' => $state, 'wizard_progress' => $state['wizard_progress'],
                    'base_revision' => $live->revision, 'revision' => $cycle->revision]);
            } else {
                $this->persistState($live, $cycle, $state);
            }

            return $this->ownedCycle($request, $id)->workingCopy();
        }, attempts: 3);
    }

    /**
     * Wizard state: crop attributes, boundary, planning snapshot and three-step progress.
     * Seed, soil and fertilizer records are not part of the wizard and are never read or rewritten here.
     *
     * @return array{attributes: array, boundary: ?array, planning_snapshot: array, wizard_progress: array, completed_step: int}
     */
    private function state(FarmCropCycle $cycle): array
    {
        if ($cycle->status === 'active' && $cycle->relationLoaded('editDraft') && $cycle->editDraft !== null) {
            $draft = $cycle->editDraft->draft_data;

            return ['attributes' => $draft['attributes'], 'boundary' => $draft['boundary'] ?? null,
                'planning_snapshot' => $draft['planning_snapshot'] ?? [], 'wizard_progress' => $cycle->progress(),
                'completed_step' => $cycle->completedSteps()];
        }

        return ['attributes' => $cycle->only($cycle->getFillable()), 'boundary' => $cycle->boundary_geojson,
            'planning_snapshot' => $cycle->planning_snapshot ?? [], 'wizard_progress' => $cycle->progress(),
            'completed_step' => $cycle->completedSteps()];
    }

    private function persistState(FarmCropCycle $live, FarmCropCycle $cycle, array $state): void
    {
        $live->fill($cycle->only($cycle->getFillable()));
        $live->forceFill(['status' => $cycle->status, 'revision' => $cycle->revision, 'completed_step' => $cycle->completed_step,
            'wizard_progress' => $state['wizard_progress'], 'planning_snapshot' => $state['planning_snapshot'],
            'finalized_at' => $cycle->finalized_at])->save();
        $live->persistBoundary($state['boundary']);
    }

    private function invalidate(array &$state, array $steps): void
    {
        foreach ($steps as $step) {
            if (($state['wizard_progress'][$step] ?? 'pending') !== 'pending') {
                $state['wizard_progress'][$step] = 'needs_review';
            }
        }
    }

    private function requireSteps(array $state, array $steps): void
    {
        $errors = [];
        foreach ($steps as $step) {
            if (($state['wizard_progress'][$step] ?? 'pending') !== 'completed') {
                $errors['wizard_progress.'.$step] = ['Complete or review this earlier step first.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Crop Selection fields. Inputs of the former seed, sowing-method, soil and fertilizer
     * steps are rejected instead of being silently ignored.
     *
     * @return array<string, array>
     */
    private function selectionRules(): array
    {
        $legacy = ['sowing_method', 'seed_id', 'seed_lot_id', 'seed_source', 'seed_quantity', 'seed_quantity_unit', 'seed_notes',
            'quantity_mode', 'seed_price_id', 'manual_estimated_cost', 'currency_code', 'assessment_mode', 'basic_assessment',
            'soil_test', 'report', 'estimated_cost', 'fertilizers', 'skipped'];

        return ['farm_id' => ['required', 'integer', 'min:1'], 'crop_id' => ['required', 'integer', 'min:1'],
            'crop_variety_id' => ['nullable', 'integer', 'min:1'], 'season_id' => ['required', 'integer', 'min:1'],
            'sowing_date' => ['required', 'date_format:Y-m-d'],
            ...array_fill_keys($legacy, ['prohibited'])];
    }

    /** @return array{farm_id: int, crop_id: int, crop_variety_id: ?int, season_id: int, sowing_date: string} */
    private function selection(array $input): array
    {
        return ['farm_id' => (int) $input['farm_id'], 'crop_id' => (int) $input['crop_id'],
            'crop_variety_id' => isset($input['crop_variety_id']) ? (int) $input['crop_variety_id'] : null,
            'season_id' => (int) $input['season_id'], 'sowing_date' => $input['sowing_date']];
    }

    private function validateSelection(array $input, Farm $farm): void
    {
        $errors = [];
        if (! $farm->is_active) {
            $errors['farm_id'] = ['Select an active farm.'];
        }
        if (! Crop::query()->published()->whereKey($input['crop_id'])->exists()) {
            $errors['crop_id'] = ['Select an eligible published crop.'];
        }
        if (! Season::query()->published()->whereKey($input['season_id'])->exists()) {
            $errors['season_id'] = ['Select an eligible season.'];
        }
        if (isset($input['crop_variety_id']) && ! CropVariety::query()->published()->where('crop_id', $input['crop_id'])->whereKey($input['crop_variety_id'])->exists()) {
            $errors['crop_variety_id'] = ['Select an eligible variety belonging to this crop.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function contextFingerprint(Farm $farm): string
    {
        $context = $farm->regionContext();
        $hierarchy = array_map(fn (array $region): array => Arr::only($region, ['id', 'parent_id', 'region_type']), $context['applicable_regions']);

        return hash('sha256', json_encode([$farm->id, $hierarchy, $context['issue'], $farm->land_type, $farm->soil_type], JSON_THROW_ON_ERROR));
    }

    private function decimal(string|int|float $value, int $scale = 4): string
    {
        return (string) BigDecimal::of((string) $value)->toScale($scale, RoundingMode::HALF_UP);
    }

    private function validateAllocation(Farm $farm, ?array $boundary, ?string $area, ?string $unit, int $excludingId): void
    {
        $sqm = AreaHelper::toSquareMeters($area, $unit);
        if ($sqm === null || BigDecimal::of($sqm)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['cultivated_area' => ['A positive cultivated area with supported units is required.']]);
        }
        $active = $farm->cropCycles()->where('status', 'active')->whereKeyNot($excludingId)->lockForUpdate();
        if ($boundary === null) {
            if ($farm->has_boundary) {
                throw ValidationException::withMessages(['boundary' => ['Select a crop boundary within this mapped farm.']]);
            }
            $farmSqm = $farm->area_sqm;
            if ($farmSqm === null || BigDecimal::of($sqm)->isGreaterThan($farmSqm)) {
                throw ValidationException::withMessages(['cultivated_area' => ['The cultivated area must fit the recorded farm area.']]);
            }
            if ($active->exists()) {
                abort(409, 'Map the farm before allocating multiple active crops.');
            }

            return;
        }
        if (! $farm->has_boundary) {
            throw ValidationException::withMessages(['boundary' => ['Map the parent farm before saving a spatial crop boundary.']]);
        }
        if (DB::getDriverName() === 'sqlite') {
            throw ValidationException::withMessages(['boundary' => ['Spatial crop allocations require the configured MySQL spatial database.']]);
        }
        $json = json_encode($boundary, JSON_THROW_ON_ERROR);
        if (! DB::connection()->isMaria()) {
            $valid = DB::selectOne('SELECT ST_IsValid('.FarmCropCycle::geometryExpression().') AS valid', [$json]);
            if (! $valid->valid) {
                throw ValidationException::withMessages(['boundary' => ['The crop boundary has invalid holes or overlapping polygon components.']]);
            }
        } else {
            if (count($boundary['coordinates']) !== 1 || count($boundary['coordinates'][0]) !== 1) {
                throw ValidationException::withMessages(['boundary' => ['Multiple parcels and holes require MySQL 8.4 topology validation.']]);
            }
        }
        $inside = DB::selectOne('SELECT ST_Contains(boundary, '.FarmCropCycle::geometryExpression().') AS inside FROM farms WHERE id = ?', [$json, $farm->id]);
        if (! $inside->inside) {
            throw ValidationException::withMessages(['boundary' => ['The crop boundary must be inside or equal to the farm boundary.']]);
        }
        if ((clone $active)->whereNull('boundary')->exists()) {
            abort(409, 'An active crop has no verified boundary. Resolve its allocation before adding another crop.');
        }
        $expression = FarmCropCycle::geometryExpression();
        if ($active->whereRaw('ST_Intersects(boundary, '.$expression.') AND NOT ST_Touches(boundary, '.$expression.')', [$json, $json])->exists()) {
            abort(409, 'The selected boundary overlaps another active crop.');
        }
    }

    /** @return array<string, mixed> */
    private function boundaryOverview(Farm $farm, ?int $excludingId = null): array
    {
        $active = $farm->cropCycles()->where('status', 'active')->when($excludingId !== null, fn (Builder $query): Builder => $query->whereKeyNot($excludingId))
            ->withBoundary()->with('crop.translations')->orderBy('farm_crop_cycles.id')->get();
        $union = null;
        $manualArea = BigDecimal::zero();
        foreach ($active as $cycle) {
            if ($cycle->boundary_geojson === null) {
                $value = AreaHelper::toSquareMeters($cycle->cultivated_area, $cycle->area_unit);
                if ($value !== null) {
                    $manualArea = $manualArea->plus($value);
                }

                continue;
            }
            if ($union === null) {
                $union = $cycle->boundary_geojson;

                continue;
            }
            if (DB::getDriverName() === 'sqlite') {
                continue;
            }
            $expression = FarmCropCycle::geometryExpression();
            $result = DB::selectOne('SELECT ST_AsGeoJSON(ST_Union('.$expression.', '.$expression.')) AS geometry', [
                json_encode($union, JSON_THROW_ON_ERROR), json_encode($cycle->boundary_geojson, JSON_THROW_ON_ERROR)]);
            $union = json_decode($result->geometry, true, flags: JSON_THROW_ON_ERROR);
            if ($union['type'] === 'Polygon') {
                $union = ['type' => 'MultiPolygon', 'coordinates' => [$union['coordinates']]];
            }
        }
        $occupied = $union === null ? $manualArea : $manualArea->plus(Farm::boundaryMetrics($union)['square_metres']);
        $measured = $farm->boundary_geojson === null ? null : Farm::boundaryMetrics($farm->boundary_geojson)['square_metres'];
        $total = $measured ?? $farm->area_sqm;
        $remaining = $total === null ? null : BigDecimal::of($total)->minus($occupied);
        if ($remaining?->isNegative()) {
            $remaining = BigDecimal::zero();
        }

        return ['farm' => new FarmResource($farm), 'farm_boundary' => $farm->boundary_geojson,
            'farm_location' => $farm->only(['state_id', 'district_id', 'tehsil_id', 'village', 'address']),
            'recorded_farm_area' => ['value' => $farm->area, 'unit' => $farm->area_unit, 'square_metres' => $farm->area_sqm],
            'measured_farm_area' => ['value' => $farm->actual_area, 'unit' => $farm->actual_area_unit, 'square_metres' => $measured],
            'existing_active_crops' => $active->map(fn (FarmCropCycle $cycle): array => ['id' => $cycle->id, 'crop_id' => $cycle->crop_id,
                'crop_name' => $cycle->crop?->translatedName(), 'crop_color' => $cycle->crop?->crop_color, 'boundary' => $cycle->boundary_geojson,
                'cultivated_area' => $cycle->cultivated_area, 'area_unit' => $cycle->area_unit]),
            'existing_crop_area' => ['square_metres' => (string) $occupied, 'value' => AreaHelper::fromSquareMeters((string) $occupied, $farm->area_unit), 'unit' => $farm->area_unit],
            'remaining_area' => ['square_metres' => $remaining === null ? null : (string) $remaining,
                'value' => $remaining === null ? null : AreaHelper::fromSquareMeters((string) $remaining, $farm->area_unit), 'unit' => $farm->area_unit],
            'can_use_full_farm_boundary' => $farm->has_boundary && $active->isEmpty()];
    }

    /**
     * Informational harvest range: sowing date plus the variety's verified maturity days.
     * Unavailable when the variety, its verified maturity range or the sowing date is missing.
     *
     * @return array{maturity_status: string, maturity_days_min: ?int, maturity_days_max: ?int,
     *     expected_harvest_date: ?string, expected_harvest_date_min: ?string, expected_harvest_date_max: ?string}
     */
    private function harvestEstimate(FarmCropCycle $cycle): array
    {
        $variety = $cycle->crop_variety_id === null ? null : CropVariety::query()->with('knowledgeSources')->find($cycle->crop_variety_id);
        if ($variety === null || $cycle->sowing_date === null || ! $variety->hasVerifiedMaturity()) {
            return ['maturity_status' => 'unavailable', 'maturity_days_min' => null, 'maturity_days_max' => null,
                'expected_harvest_date' => null, 'expected_harvest_date_min' => null, 'expected_harvest_date_max' => null];
        }
        $min = $variety->maturity_days_min;
        $max = $variety->maturity_days_max;
        $minDate = $cycle->sowing_date->addDays($min ?? $max)->toDateString();
        $maxDate = $cycle->sowing_date->addDays($max ?? $min)->toDateString();

        return ['maturity_status' => 'available', 'maturity_days_min' => $min, 'maturity_days_max' => $max,
            'expected_harvest_date' => $minDate === $maxDate ? $minDate : null, 'expected_harvest_date_min' => $minDate, 'expected_harvest_date_max' => $maxDate];
    }

    /** @return array<string, list<string>> */
    private function validationIssues(FarmCropCycle $cycle, array $state, bool $checkAllocation = true): array
    {
        $issues = [];
        foreach (FarmCropCycle::DATA_STEPS as $step) {
            if (($state['wizard_progress'][$step] ?? 'pending') !== 'completed') {
                $issues['wizard_progress.'.$step] = ['Complete or review this step before saving the crop.'];
            }
        }
        try {
            $this->validateSelection($cycle->only(FarmCropCycle::SELECTION_FIELDS), $cycle->farm);
        } catch (ValidationException $exception) {
            $issues = [...$issues, ...$exception->errors()];
        }
        if ($cycle->sowing_date === null) {
            $issues['sowing_date'] = ['Save a valid sowing date.'];
        }
        if ($cycle->cultivated_area === null || $cycle->area_unit === null) {
            $issues['cultivated_area'] = ['Save the cultivated boundary or permitted manual area.'];
        }
        if ($checkAllocation && $cycle->cultivated_area !== null) {
            try {
                $this->validateAllocation($cycle->farm, $state['boundary'], $cycle->cultivated_area, $cycle->area_unit, $cycle->id);
            } catch (ValidationException $exception) {
                $issues = [...$issues, ...$exception->errors()];
            } catch (HttpExceptionInterface $exception) {
                $issues['boundary'] = [$exception->getMessage()];
            }
        }

        return $issues;
    }

    private function detail(FarmCropCycle $cycle): FarmCropCycle
    {
        $cycle->load(['crop.translations', 'crop.category.translations', 'cropVariety.translations', 'season.translations']);
        $state = $this->state($cycle);
        $historical = $cycle->status !== 'draft' && $cycle->editDraft === null;
        $issues = $historical ? [] : $this->validationIssues($cycle, $state);
        $progress = $state['wizard_progress'];
        foreach (array_keys($issues) as $key) {
            $step = match (true) {
                str_starts_with($key, 'wizard_progress.') => substr($key, strlen('wizard_progress.')),
                in_array($key, ['farm_id', 'crop_id', 'crop_variety_id', 'season_id', 'sowing_date'], true) => 'crop_selection',
                in_array($key, ['boundary', 'cultivated_area', 'area_unit'], true) => 'boundary',
                default => null,
            };
            if ($step !== null) {
                $progress[$step] = 'needs_review';
            }
        }
        $cycle->wizard_progress = $progress;
        $sqm = $state['planning_snapshot']['boundary']['square_metres'] ?? AreaHelper::toSquareMeters($cycle->cultivated_area, $cycle->area_unit);
        $cycle->setAttribute('calculated_area_sqm', $sqm);
        $context = $cycle->farm->regionContext();
        $overview = $this->boundaryOverview($cycle->farm, $cycle->id);
        $boundary = ['boundary' => $state['boundary'], ...$cycle->only(FarmCropCycle::BOUNDARY_FIELDS),
            'calculated_area_sqm' => $sqm, 'calculated_area_ha' => AreaHelper::fromSquareMeters($sqm, 'hectare'),
            'farm_boundary' => $cycle->farm->boundary_geojson, 'existing_crop_boundaries' => $overview['existing_active_crops'],
            'farm_area_statistics' => Arr::only($overview, ['recorded_farm_area', 'measured_farm_area', 'existing_crop_area', 'remaining_area', 'can_use_full_farm_boundary'])];
        // Saved crops keep the estimate accepted when they were saved (the former wizard stored it under "seed").
        $maturity = $historical
            ? ($state['planning_snapshot']['maturity'] ?? $state['planning_snapshot']['seed']['maturity'] ?? [
                'maturity_status' => $cycle->expected_harvest_date_min === null ? 'unavailable' : 'available',
                'maturity_days_min' => null, 'maturity_days_max' => null,
                ...array_map(fn ($date) => $date?->toDateString(), $cycle->only(FarmCropCycle::HARVEST_FIELDS))])
            : $this->harvestEstimate($cycle);
        $cycle->setAttribute('detail_data', [
            'crop_cycle_id' => $cycle->id, 'regional_context' => $context, 'farm_region' => $context['applicable_regions'][0] ?? null,
            'applicable_regions' => $context['applicable_regions'], 'regional_context_available' => $context['regional_context_available'],
            'boundary_details' => $boundary, 'maturity' => $maturity,
            'validation_issues' => (object) $issues, 'can_finalize' => ! $historical && $issues === [],
            'has_pending_edit' => $cycle->editDraft !== null, 'timeline_generated' => false,
            'estimates_are_historical' => $historical, 'accepted_regional_context' => $historical ? ($state['planning_snapshot']['region_context'] ?? null) : null,
            'saved_step_data' => ['crop_selection' => [...$cycle->only(['farm_id', 'crop_id', 'crop_variety_id', 'season_id']),
                'sowing_date' => $cycle->sowing_date?->toDateString()], 'boundary' => $boundary],
        ]);

        return $cycle;
    }

    public function currentSoilReport(Request $request, string $id): StreamedResponse
    {
        $cycle = $this->ownedCycle($request, $id)->workingCopy();
        $state = $this->state($cycle);
        $path = $state['soil_test']['report_file'] ?? null;
        abort_if($path === null || ! Storage::disk(config('farm_crops.soil_reports_disk'))->exists($path), 404, 'Soil report not found.');

        return Storage::disk(config('farm_crops.soil_reports_disk'))->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    private function deleteReport(string $path, bool $checkReferences = true): void
    {
        try {
            if ($checkReferences && (SoilTest::query()->where('report_file', $path)->exists()
                || FarmCropEditDraft::query()->where('draft_data->soil_test->report_file', $path)->exists())) {
                return;
            }
            if (! Storage::disk(config('farm_crops.soil_reports_disk'))->delete($path)) {
                Log::warning('Soil report cleanup failed.');
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

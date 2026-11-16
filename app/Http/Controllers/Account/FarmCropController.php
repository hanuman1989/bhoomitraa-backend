<?php

namespace App\Http\Controllers\Account;

use App\Helpers\AreaHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Account\FarmCropResource;
use App\Http\Resources\Account\FertilizerResource;
use App\Http\Resources\Account\RegionalRecordResource;
use App\Http\Resources\Account\SeedLotResource;
use App\Http\Resources\Account\SeedResource;
use App\Http\Resources\FarmResource;
use App\Models\Crop;
use App\Models\CropSeedRate;
use App\Models\CropVariety;
use App\Models\CropYieldBenchmark;
use App\Models\Farm;
use App\Models\FarmCropCycle;
use App\Models\FarmCropEditDraft;
use App\Models\FarmCropFertilizerPlan;
use App\Models\FarmCropSoilAssessment;
use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use App\Models\FertilizerPrice;
use App\Models\FertilizerRecommendation;
use App\Models\FertilizerRecommendationNutrient;
use App\Models\KnowledgeSource;
use App\Models\Region;
use App\Models\Season;
use App\Models\Seed;
use App\Models\SeedLot;
use App\Models\SeedPrice;
use App\Models\SoilTest;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        $selection = array_replace(array_fill_keys(FarmCropCycle::SELECTION_FIELDS, null), Arr::only($input, FarmCropCycle::SELECTION_FIELDS));
        foreach (['farm_id', 'crop_id', 'crop_variety_id', 'season_id'] as $field) {
            if ($selection[$field] !== null) {
                $selection[$field] = (int) $selection[$field];
            }
        }
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
            $progress = array_fill_keys(FarmCropCycle::STEPS, 'pending');
            $progress['crop_selection'] = 'completed';
            $context = $farm->regionContext();
            $cycle->forceFill(['created_by' => $user->id, 'creation_key' => $creationKey, 'creation_payload_hash' => $fingerprint,
                'status' => 'draft', 'completed_step' => 1, 'revision' => 1, 'wizard_progress' => $progress,
                'planning_snapshot' => ['region_context' => $context, 'context_fingerprint' => $this->contextFingerprint($farm)]])->save();

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
            $this->validateSelection($input, $farm);
            $previous = $cycle->only(FarmCropCycle::SELECTION_FIELDS);
            $cycle->fill(array_replace(array_fill_keys(FarmCropCycle::SELECTION_FIELDS, null), Arr::only($input, FarmCropCycle::SELECTION_FIELDS)));
            $cycle->setRelation('farm', $farm);
            $farmChanged = (int) $previous['farm_id'] !== $cycle->farm_id;
            $cropChanged = (int) $previous['crop_id'] !== $cycle->crop_id;
            $varietyChanged = $previous['crop_variety_id'] !== $cycle->crop_variety_id;
            if ($farmChanged) {
                $oldReport = $state['soil_test']['report_file'] ?? null;
                if ($oldReport !== null && ($cycle->status === 'draft' || ! $cycle->soilTests()->where('report_file', $oldReport)->exists())) {
                    $state['obsolete_files'][] = $oldReport;
                }
                $cycle->fill(array_fill_keys(FarmCropCycle::BOUNDARY_FIELDS, null));
                $state['boundary'] = null;
                $state['planning_snapshot']['boundary'] = null;
                $this->invalidate($state, ['boundary', 'seed_sowing', 'soil_assessment', 'fertilizer_planning']);
                $state['soil_assessment'] = null;
                $state['soil_test'] = null;
                $state['planning_snapshot']['soil'] = null;
            }
            if ($cropChanged || $varietyChanged) {
                if ($cycle->seed_id !== null && ! Seed::query()->published()->whereKey($cycle->seed_id)->where('crop_id', $cycle->crop_id)
                    ->where(fn (Builder $query): Builder => $cycle->crop_variety_id === null ? $query->whereNull('crop_variety_id')
                        : $query->whereNull('crop_variety_id')->orWhere('crop_variety_id', $cycle->crop_variety_id))->exists()) {
                    $cycle->seed_id = null;
                    $cycle->seed_lot_id = null;
                }
                $cycle->fill(['expected_harvest_date' => null, 'expected_harvest_date_min' => null, 'expected_harvest_date_max' => null]);
                $this->invalidate($state, ['seed_sowing', 'fertilizer_planning']);
            } elseif ($previous['season_id'] !== $cycle->season_id || $previous['sowing_method'] !== $cycle->sowing_method) {
                $this->invalidate($state, ['seed_sowing', 'fertilizer_planning']);
            }
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
            $this->invalidate($state, ['seed_sowing', 'fertilizer_planning']);
            $state['wizard_progress']['boundary'] = 'completed';
        });

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Crop boundary saved successfully.']);
    }

    public function saveStepThree(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'seed_source' => ['required', Rule::in(config('farm_crops.seed_sources'))],
            'seed_id' => ['required_if:seed_source,catalogue', 'nullable', 'integer', 'min:1'],
            'seed_lot_id' => ['nullable', 'integer', 'min:1'], 'seed_notes' => ['nullable', 'string', 'max:2000'],
            'sowing_date' => ['required', 'date_format:Y-m-d'], 'quantity_mode' => ['required', Rule::in(['suggested', 'custom', 'unknown'])],
            'seed_quantity' => ['required_if:quantity_mode,custom', 'nullable', 'numeric', 'gt:0', 'max:9999999999.9999', 'decimal:0,4'],
            'seed_quantity_unit' => ['required_if:quantity_mode,custom', 'nullable', Rule::in(Seed::UNIT_CODES)],
            'seed_price_id' => ['nullable', 'integer', 'min:1'], 'manual_estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'currency_code' => ['sometimes', Rule::in(config('seed_prices.currencies'))]]);
        $cycle = $this->mutate($request, $id, function (FarmCropCycle $cycle, array &$state) use ($input): void {
            $this->requireSteps($state, ['crop_selection', 'boundary']);
            $seed = $input['seed_source'] === 'catalogue' ? $this->compatibleSeed($cycle, $input['seed_id']) : null;
            if ($seed === null && (! empty($input['seed_id']) || ! empty($input['seed_lot_id']) || ! empty($input['seed_price_id']))) {
                throw ValidationException::withMessages(['seed_id' => ['Only catalogue seed may reference a product, lot or package price.']]);
            }
            $lot = null;
            if (isset($input['seed_lot_id'])) {
                $lot = $this->eligibleLot($seed->id, (int) $input['seed_lot_id']);
            }
            $cycle->fill(['seed_id' => $seed?->id, 'seed_lot_id' => $lot?->id, 'seed_source' => $input['seed_source'],
                'seed_notes' => $input['seed_notes'] ?? $cycle->seed_notes, 'sowing_date' => $input['sowing_date']]);
            $recommendation = $this->seedRecommendation($cycle, $seed);
            $mode = $input['quantity_mode'];
            $quantity = match ($mode) {
                'suggested' => $recommendation['recommended_quantity'],
                'custom' => $input['seed_quantity'],
                default => null,
            };
            $unit = $mode === 'suggested' ? $recommendation['quantity_unit'] : ($mode === 'custom' ? $input['seed_quantity_unit'] : null);
            if ($mode === 'suggested' && $quantity === null) {
                throw ValidationException::withMessages(['quantity_mode' => ['No unambiguous supported seed recommendation is available. Enter a custom quantity or choose unknown.']]);
            }
            if ($quantity !== null && (BigDecimal::of((string) $quantity)->isLessThanOrEqualTo(0)
                || BigDecimal::of((string) $quantity)->isGreaterThan('9999999999.9999'))) {
                throw ValidationException::withMessages(['seed_quantity' => ['The planned quantity must be positive and fit the supported precision.']]);
            }
            if ($seed !== null && $unit !== null && ! in_array($unit, $seed->compatibleUnits(), true)) {
                throw ValidationException::withMessages(['seed_quantity_unit' => ['The quantity unit is incompatible with the selected planting material.']]);
            }
            $cycle->seed_quantity = $quantity;
            $cycle->seed_quantity_unit = $unit;
            $maturity = $this->harvestEstimate($cycle);
            $cycle->fill(Arr::only($maturity, ['expected_harvest_date', 'expected_harvest_date_min', 'expected_harvest_date_max']));
            $purchase = $this->purchaseEstimate($cycle, $seed?->id, $input['seed_price_id'] ?? null, $quantity, $unit,
                $input['manual_estimated_cost'] ?? null, 'seed', $input['currency_code'] ?? config('seed_prices.default_currency'));
            $state['planning_snapshot']['seed'] = ['quantity_mode' => $mode, ...$recommendation, 'purchase' => $purchase,
                'maturity' => $maturity, 'calculated_at' => now()->toISOString()];
            $state['wizard_progress']['seed_sowing'] = 'completed';
        });

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Seed and sowing details saved successfully.']);
    }

    public function saveStepFour(Request $request, string $id): JsonResponse
    {
        $rules = ['revision' => ['required', 'integer', 'min:1'], 'assessment_mode' => ['required', Rule::in(['basic', 'soil_test', 'skipped'])],
            'notes' => ['nullable', 'string', 'max:2000'], 'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'test_date' => ['required_if:assessment_mode,soil_test', 'nullable', 'date_format:Y-m-d'],
            'sample_collected_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:test_date'],
            'laboratory_name' => ['nullable', 'string', 'max:200'], 'report_number' => ['nullable', 'string', 'max:100'],
            'sample_depth_cm' => ['nullable', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
            'report' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'extensions:pdf,jpg,jpeg,png,webp', 'max:'.config('farm_crops.soil_report_max_kb')],
            'remove_report' => ['sometimes', 'boolean'], 'measurement_units' => ['sometimes', 'array:'.implode(',', SoilTest::MEASUREMENTS)]];
        foreach (config('farm_crops.soil_assessment') as $field => $values) {
            $rules[$field] = ['nullable', Rule::in($values)];
        }
        foreach (SoilTest::MEASUREMENTS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'min:0', 'max:'.($field === 'soil_ph' ? '14' : '9999999999.9999'), 'decimal:0,4'];
            $rules['measurement_units.'.$field] = ['nullable', Rule::in(config('farm_crops.soil_measurement_units.'.$field))];
        }
        $input = $request->validate($rules);
        $newFiles = [];
        try {
            $cycle = $this->mutate($request, $id, function (FarmCropCycle $cycle, array &$state) use ($input, $request, &$newFiles): void {
                $this->requireSteps($state, ['crop_selection', 'boundary']);
                $mode = $input['assessment_mode'];
                $oldReport = $state['soil_test']['report_file'] ?? null;
                $oldCost = $state['planning_snapshot']['soil']['estimated_cost'] ?? null;
                $oldMode = $state['planning_snapshot']['soil']['assessment_mode'] ?? null;
                $state['planning_snapshot']['soil'] = ['assessment_mode' => $mode, 'estimated_cost' => $mode !== 'skipped'
                    ? (array_key_exists('estimated_cost', $input) ? $input['estimated_cost'] : ($mode === $oldMode ? $oldCost : null)) : null];
                if ($mode === 'skipped') {
                    $state['soil_assessment'] = null;
                    $state['soil_test'] = null;
                } elseif ($mode === 'basic') {
                    $state['soil_test'] = null;
                    $state['soil_assessment'] = [...($state['soil_assessment'] ?? []), ...Arr::only($input, [...array_keys(config('farm_crops.soil_assessment')), 'notes']),
                        'assessment_source' => 'farmer', 'assessed_at' => now()->toISOString()];
                } else {
                    $test = array_replace($state['soil_test'] ?? [], Arr::only($input, (new SoilTest)->getFillable()));
                    if ($request->hasFile('report')) {
                        $path = $newFiles[0] ?? $request->file('report')->store('soil-reports/'.$cycle->id, config('farm_crops.soil_reports_disk'));
                        if ($path === false) {
                            throw new \RuntimeException('Unable to store soil report.');
                        }
                        if ($newFiles === []) {
                            $newFiles[] = $path;
                        }
                        $test['report_file'] = $path;
                    } elseif ($input['remove_report'] ?? false) {
                        $test['report_file'] = null;
                    }
                    $meaningful = ! empty($test['report_file']);
                    foreach (SoilTest::MEASUREMENTS as $field) {
                        if (isset($test[$field])) {
                            $meaningful = true;
                            if ($field !== 'soil_ph' && empty($test['measurement_units'][$field])) {
                                throw ValidationException::withMessages(['measurement_units.'.$field => ['Supply the measurement unit recorded on the soil report.']]);
                            }
                        }
                    }
                    if (! $meaningful) {
                        throw ValidationException::withMessages(['soil_test' => ['Enter at least one measurement or attach a report.']]);
                    }
                    $state['soil_test'] = $test;
                    $state['soil_assessment'] = null;
                }
                if ($oldReport !== null && $oldReport !== ($state['soil_test']['report_file'] ?? null)
                    && ($cycle->status === 'draft' || ! $cycle->soilTests()->where('report_file', $oldReport)->exists())) {
                    $state['obsolete_files'][] = $oldReport;
                }
                $this->invalidate($state, ['fertilizer_planning']);
                $state['wizard_progress']['soil_assessment'] = $mode === 'skipped' ? 'skipped' : 'completed';
            });
        } catch (Throwable $exception) {
            foreach ($newFiles as $path) {
                $this->deleteReport($path, false);
            }
            throw $exception;
        }

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Soil assessment saved successfully.']);
    }

    public function saveStepFive(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'skipped' => ['required', 'boolean'],
            'fertilizers' => ['required_unless:skipped,true', 'array', 'max:30'],
            'fertilizers.*' => ['array:id,fertilizer_id,custom_name,fertilizer_recommendation_id,quantity_mode,planned_quantity,quantity_unit_code,fertilizer_price_id,manual_estimated_cost,currency_code,notes'],
            'fertilizers.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'fertilizers.*.fertilizer_id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'fertilizers.*.custom_name' => ['nullable', 'string', 'max:200'],
            'fertilizers.*.fertilizer_recommendation_id' => ['nullable', 'integer', 'min:1'],
            'fertilizers.*.quantity_mode' => ['required', Rule::in(['custom'])],
            'fertilizers.*.planned_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.9999', 'decimal:0,4'],
            'fertilizers.*.quantity_unit_code' => ['required', Rule::in(Fertilizer::UNIT_CODES)],
            'fertilizers.*.fertilizer_price_id' => ['nullable', 'integer', 'min:1'],
            'fertilizers.*.manual_estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'fertilizers.*.currency_code' => ['sometimes', Rule::in(config('seed_prices.currencies'))], 'fertilizers.*.notes' => ['nullable', 'string', 'max:2000']]);
        $cycle = $this->mutate($request, $id, function (FarmCropCycle $cycle, array &$state) use ($input): void {
            $this->requireSteps($state, ['crop_selection', 'boundary', 'seed_sowing']);
            $state['fertilizer_plans'] = [];
            if ($input['skipped']) {
                $state['wizard_progress']['fertilizer_planning'] = 'skipped';
                $state['planning_snapshot']['fertilizer'] = null;

                return;
            }
            $recommendation = $this->fertilizerRecommendation($cycle);
            $customNames = [];
            foreach ($input['fertilizers'] as $index => $row) {
                if (isset($row['id']) && ! $cycle->fertilizerPlans()->whereKey($row['id'])->exists()) {
                    throw ValidationException::withMessages(['fertilizers.'.$index.'.id' => ['Select a plan belonging to this crop.']]);
                }
                $product = isset($row['fertilizer_id']) ? Fertilizer::query()->published()->with(['translations', 'images', 'nutrients'])->find($row['fertilizer_id']) : null;
                if (isset($row['fertilizer_id']) && $product === null) {
                    throw ValidationException::withMessages(['fertilizers.'.$index.'.fertilizer_id' => ['Select an eligible fertilizer product.']]);
                }
                if ($product === null) {
                    $name = mb_strtolower(trim($row['custom_name'] ?? ''));
                    if ($name === '' || isset($customNames[$name])) {
                        throw ValidationException::withMessages(['fertilizers.'.$index.'.custom_name' => ['Provide a distinct custom product name.']]);
                    }
                    $customNames[$name] = true;
                } elseif (FarmCropCycle::convertQuantity('1', $row['quantity_unit_code'], $product->default_unit_code) === null) {
                    throw ValidationException::withMessages(['fertilizers.'.$index.'.quantity_unit_code' => ['The quantity unit must match the product quantity dimension.']]);
                }
                $recommendationId = $row['fertilizer_recommendation_id'] ?? null;
                if ($recommendationId !== null) {
                    $eligible = $this->fertilizerQuery($cycle)->find($recommendationId);
                    if ($eligible === null || $product !== null && ! $product->nutrients->pluck('nutrient_code')->intersect($eligible->nutrients->pluck('nutrient_code'))->isNotEmpty()) {
                        throw ValidationException::withMessages(['fertilizers.'.$index.'.fertilizer_recommendation_id' => ['Select an applicable recommendation with compatible nutrient codes.']]);
                    }
                }
                $purchase = $this->purchaseEstimate($cycle, $product?->id, $row['fertilizer_price_id'] ?? null, $row['planned_quantity'], $row['quantity_unit_code'],
                    $row['manual_estimated_cost'] ?? null, 'fertilizer', $row['currency_code'] ?? config('seed_prices.default_currency'));
                $state['fertilizer_plans'][] = [...Arr::only($row, ['id', 'custom_name', 'fertilizer_recommendation_id', 'quantity_mode', 'planned_quantity', 'quantity_unit_code', 'notes']),
                    'fertilizer_id' => $product?->id, 'recommended_quantity' => null, 'fertilizer_price_id' => $purchase['price_id'],
                    ...Arr::only($purchase, ['package_quantity', 'package_unit_code', 'package_count', 'package_price', 'estimated_cost', 'currency_code']),
                    'calculation_snapshot' => ['purchase' => $purchase, 'recommendation' => $recommendationId === null ? null : $this->regionalData($eligible, $cycle->farm->regionContext()['region_ids']),
                        'quantity_mode' => 'custom', 'calculated_at' => now()->toISOString()]];
            }
            if ($state['fertilizer_plans'] === []) {
                throw ValidationException::withMessages(['fertilizers' => ['Select at least one fertilizer or skip planning.']]);
            }
            $state['planning_snapshot']['fertilizer'] = $recommendation;
            $state['wizard_progress']['fertilizer_planning'] = 'completed';
        });

        return response()->json(['status' => true, 'data' => new FarmCropResource($this->detail($cycle)), 'pagination' => null, 'message' => 'Fertilizer plan saved successfully.']);
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

    public function getSeedRecommendation(Request $request, string $id): JsonResponse
    {
        $input = $request->validate(['seed_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $cycle = $this->ownedCycle($request, $id)->workingCopy();
        $seedId = $input['seed_id'] ?? $cycle->seed_id;
        $seed = $seedId === null ? null : $this->compatibleSeed($cycle, $seedId);

        return response()->json(['status' => true, 'data' => [...$this->seedRecommendation($cycle, $seed), 'region_context' => $cycle->farm->regionContext()],
            'pagination' => null, 'message' => 'Seed recommendation retrieved successfully.']);
    }

    public function getFertilizerRecommendation(Request $request, string $id): JsonResponse
    {
        $cycle = $this->ownedCycle($request, $id)->workingCopy();

        return response()->json(['status' => true, 'data' => [...$this->fertilizerRecommendation($cycle), 'region_context' => $cycle->farm->regionContext()],
            'pagination' => null, 'message' => 'Fertilizer recommendation retrieved successfully.']);
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
            $yield = $this->yieldEstimate($cycle);
            $state['planning_snapshot']['expenses'] = $this->expenses($cycle, $state);
            $state['planning_snapshot']['expected_yield'] = $yield;
            $state['planning_snapshot']['finalize_request_revision'] = (int) $input['revision'];
            $state['planning_snapshot']['accepted_at'] = now()->toISOString();
            $state['planning_snapshot']['accepted_fertilizer_plans'] = $state['fertilizer_plans'];
            $state['planning_snapshot']['accepted_soil_assessment'] = $state['soil_assessment'];
            $state['planning_snapshot']['region_context'] = $cycle->farm->regionContext();
            $state['planning_snapshot']['context_fingerprint'] = $this->contextFingerprint($cycle->farm);
            $cycle->status = 'active';
            $cycle->finalized_at = now();
            $cycle->revision++;
            $cycle->completed_step = 6;
            $this->persistState($live, $cycle, $state, true);
            if ($yield['availability_status'] === 'available') {
                $live->yieldEstimates()->create(['yield_benchmark_id' => $yield['benchmark']['id'], 'estimate_type' => 'benchmark',
                    ...Arr::only($yield, ['estimated_min_yield', 'estimated_avg_yield', 'estimated_max_yield', 'yield_unit']),
                    'cultivated_area' => $cycle->cultivated_area, 'area_unit' => $cycle->area_unit,
                    'estimated_at' => now(), 'input_snapshot' => $yield, 'model_version' => null]);
            }
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
        $obsolete = [];
        $saved = DB::transaction(function () use ($request, $id, $save, $targetFarmId, &$obsolete): FarmCropCycle {
            $live = $this->lockCycle($request, $id, $targetFarmId);
            abort_unless(in_array($live->status, ['draft', 'active'], true), 409, 'Historical crops cannot be edited.');
            $cycle = $live->workingCopy();
            abort_unless((int) $request->input('revision') === $cycle->revision, 409, 'The crop changed. Reload the current revision.');
            if ($live->editDraft !== null) {
                abort_unless($live->editDraft->base_revision === $live->revision, 409, 'The live crop changed after this edit began.');
            }
            $state = $this->state($cycle);
            if (($state['planning_snapshot']['context_fingerprint'] ?? null) !== $this->contextFingerprint($cycle->farm)) {
                $this->invalidate($state, ['seed_sowing', 'fertilizer_planning']);
            }
            $save($cycle, $state);
            $cycle->revision++;
            $cycle->wizard_progress = $state['wizard_progress'];
            $cycle->completed_step = 0;
            foreach (FarmCropCycle::STEPS as $step) {
                if (! in_array($state['wizard_progress'][$step] ?? 'pending', ['completed', 'skipped'], true)) {
                    break;
                }
                $cycle->completed_step++;
            }
            $state['planning_snapshot']['region_context'] = $cycle->farm->regionContext();
            $state['planning_snapshot']['context_fingerprint'] = $this->contextFingerprint($cycle->farm);
            if ($live->status === 'active') {
                $state['attributes'] = $cycle->only($cycle->getFillable());
                $state['completed_step'] = $cycle->completed_step;
                $live->editDraft()->updateOrCreate([], ['draft_data' => Arr::except($state, ['obsolete_files']), 'wizard_progress' => $state['wizard_progress'],
                    'base_revision' => $live->revision, 'revision' => $cycle->revision]);
            } else {
                $this->persistState($live, $cycle, $state);
            }
            $obsolete = $state['obsolete_files'] ?? [];

            return $this->ownedCycle($request, $id)->workingCopy();
        }, attempts: 3);
        DB::afterCommit(function () use ($obsolete): void {
            foreach ($obsolete as $path) {
                $this->deleteReport($path);
            }
        });

        return $saved;
    }

    /** @return array<string, mixed> */
    private function state(FarmCropCycle $cycle): array
    {
        if ($cycle->status === 'active' && $cycle->relationLoaded('editDraft') && $cycle->editDraft !== null) {
            return [...$cycle->editDraft->draft_data, 'wizard_progress' => $cycle->wizard_progress];
        }
        $cycle->loadMissing(['soilAssessments', 'soilTests', 'fertilizerPlans']);
        $soilId = $cycle->planning_snapshot['soil']['soil_test_id'] ?? null;
        $test = $soilId === null ? null : $cycle->soilTests->firstWhere('id', $soilId);
        $assessmentId = $cycle->planning_snapshot['soil']['soil_assessment_id'] ?? null;
        $assessment = $assessmentId === null ? null : $cycle->soilAssessments->firstWhere('id', $assessmentId);

        return ['attributes' => $cycle->only($cycle->getFillable()), 'boundary' => $cycle->boundary_geojson,
            'planning_snapshot' => $cycle->planning_snapshot ?? [], 'wizard_progress' => $cycle->wizard_progress,
            'completed_step' => $cycle->completed_step,
            'soil_assessment' => ($cycle->planning_snapshot['soil']['assessment_mode'] ?? null) === 'basic'
                ? ($assessment === null ? null : ['id' => $assessment->id, ...$assessment->only((new FarmCropSoilAssessment)->getFillable())]) : null,
            'soil_test' => $test === null ? null : ['id' => $test->id, ...$test->only([...$test->getFillable(), 'report_file'])],
            'fertilizer_plans' => $cycle->fertilizerPlans->map(fn ($plan): array => ['id' => $plan->id, ...$plan->only($plan->getFillable())])->all()];
    }

    private function persistState(FarmCropCycle $live, FarmCropCycle $cycle, array &$state, bool $finalizing = false): void
    {
        $wasActive = $live->status === 'active';
        $live->fill($cycle->only($cycle->getFillable()));
        $live->forceFill(['status' => $cycle->status, 'revision' => $cycle->revision, 'completed_step' => $cycle->completed_step,
            'wizard_progress' => $state['wizard_progress'], 'planning_snapshot' => $state['planning_snapshot'],
            'finalized_at' => $cycle->finalized_at])->save();
        $live->persistBoundary($state['boundary']);
        if ($state['soil_assessment'] === null && ! $wasActive) {
            $live->soilAssessments()->delete();
        } elseif ($state['soil_assessment'] !== null) {
            $assessment = $wasActive && $finalizing ? $live->soilAssessments()->make()
                : $live->soilAssessments()->firstOrNew(['id' => $state['soil_assessment']['id'] ?? null]);
            $assessment->fill($state['soil_assessment'])->save();
            $state['planning_snapshot']['soil']['soil_assessment_id'] = $assessment->id;
        }
        $testData = $state['soil_test'];
        if ($testData !== null) {
            $test = $wasActive && $finalizing ? $live->soilTests()->make() : $live->soilTests()->firstOrNew(['id' => $testData['id'] ?? null]);
            $test->fill(Arr::only($testData, $test->getFillable()));
            $test->forceFill(['farm_id' => $cycle->farm_id, 'report_file' => $testData['report_file'] ?? null,
                'verification_status' => 'pending', 'verified_by' => null, 'verified_at' => null])->save();
            $state['planning_snapshot']['soil']['soil_test_id'] = $test->id;
            $state['soil_test']['id'] = $test->id;
        } elseif (! $wasActive) {
            $live->soilTests()->delete();
        }
        $keep = [];
        foreach ($state['fertilizer_plans'] as &$row) {
            $attributes = Arr::only($row, (new FarmCropFertilizerPlan)->getFillable());
            if (isset($row['id'])) {
                $plan = $live->fertilizerPlans()->findOrFail($row['id']);
                $plan->update($attributes);
            } else {
                $key = isset($row['fertilizer_id']) ? ['fertilizer_id' => $row['fertilizer_id']] : ['fertilizer_id' => null, 'custom_name' => $row['custom_name']];
                $plan = $live->fertilizerPlans()->updateOrCreate($key, $attributes);
            }
            $row['id'] = $plan->id;
            $keep[] = $plan->id;
        }
        unset($row);
        $live->fertilizerPlans()->whereNotIn('id', $keep)->delete();
        $live->forceFill(['planning_snapshot' => $state['planning_snapshot']])->save();
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
            if (! in_array($state['wizard_progress'][$step] ?? 'pending', ['completed', 'skipped'], true)) {
                $errors['wizard_progress.'.$step] = ['Complete or review this earlier step first.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, array> */
    private function selectionRules(): array
    {
        return ['farm_id' => ['required', 'integer', 'min:1'], 'crop_id' => ['required', 'integer', 'min:1'],
            'crop_variety_id' => ['nullable', 'integer', 'min:1'], 'season_id' => ['required', 'integer', 'min:1'],
            'sowing_method' => ['nullable', Rule::in(CropSeedRate::SOWING_METHODS)]];
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
            throw ValidationException::withMessages(['boundary' => ['The crop boundary must be fully contained within the farm.']]);
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
            $result = DB::selectOne('SELECT ST_AsGeoJSON(ST_Union('.$expression.', '.$expression.'), 9, 0) AS geometry', [
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

    private function compatibleSeed(FarmCropCycle $cycle, int|string $id): Seed
    {
        $seed = Seed::query()->published()->with(['translations', 'images', 'crop.translations', 'cropVariety.translations'])->where('crop_id', $cycle->crop_id)
            ->where(fn (Builder $query): Builder => $cycle->crop_variety_id === null ? $query->whereNull('crop_variety_id')
                : $query->whereNull('crop_variety_id')->orWhere('crop_variety_id', $cycle->crop_variety_id))->find($id);
        if ($seed === null) {
            throw ValidationException::withMessages(['seed_id' => ['Select an eligible seed compatible with the crop and variety.']]);
        }

        return $seed;
    }

    private function eligibleLot(int $seedId, int $id): SeedLot
    {
        $lot = SeedLot::query()->selectable()->where('seed_id', $seedId)->where('verification_status', 'verified')
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_until')->orWhere('valid_until', '>=', today()->toDateString()))
            ->where(fn (Builder $query): Builder => $query->whereNull('production_date')->orWhere('production_date', '<=', today()->toDateString()))
            ->where(fn (Builder $query): Builder => $query->whereNull('test_date')->orWhere('test_date', '<=', today()->toDateString()))->find($id);
        if ($lot === null) {
            throw ValidationException::withMessages(['seed_lot_id' => ['Select an active, verified, valid lot belonging to this seed.']]);
        }

        return $lot;
    }

    private function regionalData(Model $record, array $regionIds): array
    {
        $record->setAttribute('matching_region_ids', $regionIds);

        return [...(new RegionalRecordResource($record))->resolve(), 'record_fingerprint' => $this->recordFingerprint($record)];
    }

    private function recordFingerprint(Model $record): string
    {
        $attributes = Arr::except($record->getAttributes(), ['matching_region_ids', ...array_filter(array_keys($record->getAttributes()), fn (string $key): bool => str_starts_with($key, 'farm_region_match_'))]);
        ksort($attributes);

        $regionIds = $record->regions->modelKeys();
        sort($regionIds);

        return hash('sha256', json_encode([$attributes, $regionIds,
            $record->knowledgeSource?->updated_at?->toISOString(),
            $record instanceof FertilizerRecommendation ? $record->nutrients->map->only(['nutrient_code', 'recommended_quantity', 'unit_code', 'area_unit_code'])->all() : null], JSON_THROW_ON_ERROR));
    }

    /** @return array{availability_status: string, selected: ?Model, alternatives: array} */
    private function pickRecommendation(Builder $query, array $regionIds, array $optionalFields, bool $yield = false): array
    {
        $candidates = $query->orderBy('id')->get();
        if ($candidates->isEmpty()) {
            return ['availability_status' => 'no_compatible_recommendation', 'selected' => null, 'alternatives' => []];
        }
        $rank = function (Model $record) use ($regionIds, $optionalFields, $yield): array {
            $match = Region::agriculturalMatch($record, $regionIds);
            $ranking = [$match['region_specificity'] ?? count(Region::PARENT_TYPES)];
            foreach ($optionalFields as $field) {
                $ranking[] = $record->{$field} === null ? 1 : 0;
            }
            if ($yield) {
                $ranking[] = array_search($record->benchmark_type, ['district_average', 'state_average', 'demonstration', 'variety_potential', 'research_trial'], true);
            }
            $ranking[] = -($record->reference_year ?? 0);

            return $ranking;
        };
        $candidates = $candidates->sort(fn (Model $a, Model $b): int => $rank($a) <=> $rank($b))->values();
        $bestRank = $rank($candidates->first());
        $best = $candidates->filter(fn (Model $record): bool => $rank($record) === $bestRank)->values();
        $values = $best->map(fn (Model $record): string => json_encode(match (true) {
            $record instanceof CropSeedRate => $record->only(['rate_basis', 'rate_min', 'rate_recommended', 'rate_max', 'rate_unit_code', 'area_unit_code']),
            $record instanceof FertilizerRecommendation => $record->nutrients->sortBy('nutrient_code')->map->only(['nutrient_code', 'recommended_quantity', 'unit_code', 'area_unit_code'])->values()->all(),
            default => $record->only(['yield_min_kg_ha', 'yield_avg_kg_ha', 'yield_max_kg_ha']),
        }, JSON_THROW_ON_ERROR))->unique();
        $ambiguous = $values->count() > 1;

        return ['availability_status' => $ambiguous ? 'ambiguous_recommendation' : 'available', 'selected' => $ambiguous ? null : $best->first(),
            'alternatives' => $ambiguous ? $best->map(fn (Model $record): array => $this->regionalData($record, $regionIds))->all() : []];
    }

    private function seedRecommendation(FarmCropCycle $cycle, ?Seed $seed): array
    {
        $context = $cycle->farm->regionContext();
        $result = ['availability_status' => 'insufficient_data', 'seed_rate' => null, 'recommended_quantity' => null,
            'recommended_min_quantity' => null, 'recommended_max_quantity' => null, 'quantity_unit' => null, 'alternatives' => []];
        if ($seed === null || $cycle->cultivated_area === null) {
            return $result;
        }
        $conditions = Arr::only($cycle->recommendationConditions(), ['crop_id', 'crop_variety_id', 'season_id', 'sowing_method', 'irrigation_condition']);
        $conditions['seed_id'] = $seed->id;
        $conditions['material_type'] = $seed->material_type;
        $query = Region::applyAgriculturalContext(CropSeedRate::query(), $context['region_ids'], $conditions);
        $selection = $this->pickRecommendation($query, $context['region_ids'], ['crop_variety_id', 'seed_id', 'season_id', 'material_type', 'sowing_method', 'irrigation_condition']);
        $result['availability_status'] = $selection['availability_status'];
        $result['alternatives'] = $selection['alternatives'];
        $rate = $selection['selected'];
        if ($rate === null) {
            return $result;
        }
        $result['seed_rate'] = $this->regionalData($rate, $context['region_ids']);
        $convertedArea = AreaHelper::fromSquareMeters($cycle->squareMetres(), $rate->area_unit_code);
        if ($rate->rate_basis !== 'per_area' || $convertedArea === null || ! in_array($rate->rate_unit_code, $seed->compatibleUnits(), true)) {
            $result['availability_status'] = 'unsupported_unit_conversion';

            return $result;
        }
        if ($rate->rate_recommended === null || BigDecimal::of($rate->rate_recommended)->isLessThanOrEqualTo(0)) {
            $result['availability_status'] = 'insufficient_data';

            return $result;
        }
        foreach (['rate_recommended' => 'recommended_quantity', 'rate_min' => 'recommended_min_quantity', 'rate_max' => 'recommended_max_quantity'] as $field => $output) {
            $result[$output] = $rate->{$field} === null ? null : $this->decimal((string) BigDecimal::of($convertedArea)->multipliedBy($rate->{$field}));
        }
        $result['quantity_unit'] = $rate->rate_unit_code;

        return $result;
    }

    private function harvestEstimate(FarmCropCycle $cycle): array
    {
        $variety = $cycle->crop_variety_id === null ? null : CropVariety::query()->with('knowledgeSources')->find($cycle->crop_variety_id);
        $min = $variety?->maturity_days_min;
        $max = $variety?->maturity_days_max;
        $verified = $variety !== null && ($variety->knowledgeSources->contains(fn ($source): bool => $source->is_active && $source->verification_status === 'verified')
            || $variety->source_id !== null && KnowledgeSource::query()->whereKey($variety->source_id)->where('is_active', true)->where('verification_status', 'verified')->exists());
        if (! $verified || $cycle->sowing_date === null || $min === null && $max === null || $min !== null && $max !== null && $max < $min) {
            return ['maturity_status' => 'unavailable', 'maturity_days_min' => null, 'maturity_days_max' => null,
                'expected_harvest_date' => null, 'expected_harvest_date_min' => null, 'expected_harvest_date_max' => null];
        }
        $minDate = $cycle->sowing_date->addDays($min ?? $max)->toDateString();
        $maxDate = $cycle->sowing_date->addDays($max ?? $min)->toDateString();

        return ['maturity_status' => 'available', 'maturity_days_min' => $min, 'maturity_days_max' => $max,
            'expected_harvest_date' => $minDate === $maxDate ? $minDate : null, 'expected_harvest_date_min' => $minDate, 'expected_harvest_date_max' => $maxDate];
    }

    private function purchaseEstimate(FarmCropCycle $cycle, ?int $productId, int|string|null $priceId, string|int|float|null $quantity,
        ?string $quantityUnit, string|int|float|null $manualCost, string $kind, string $currency): array
    {
        $result = ['availability_status' => $manualCost === null ? 'no_valid_price' : 'manual_estimate', 'price_id' => null,
            'package_quantity' => null, 'package_unit_code' => null, 'package_count' => null, 'package_price' => null, 'purchase_quantity' => null,
            'estimated_cost' => $manualCost === null ? null : $this->decimal($manualCost), 'currency_code' => $currency,
            'manual_estimated_cost' => $manualCost === null ? null : $this->decimal($manualCost), 'pricing_date' => today()->toDateString(),
            'price' => null];
        if ($priceId === null) {
            return $result;
        }
        $field = $kind.'_price_id';
        if ($productId === null || $quantity === null || $quantityUnit === null) {
            throw ValidationException::withMessages([$field => ['Select a product and a known planned quantity before selecting a price.']]);
        }
        $class = $kind === 'seed' ? SeedPrice::class : FertilizerPrice::class;
        $regions = $cycle->farm->regionContext()['region_ids'];
        $price = Region::applyPlanningPrices($class::query(), $regions, today()->toDateString(), $currency)
            ->where($kind.'_id', $productId)->find($priceId);
        if ($price === null) {
            throw ValidationException::withMessages([$field => ['The selected package price is not active, current, in the selected currency and applicable to this farm.']]);
        }
        $converted = FarmCropCycle::convertQuantity((string) $quantity, $quantityUnit, $price->package_unit_code);
        if ($converted === null) {
            throw ValidationException::withMessages([$field => ['The package unit is incompatible with the planned quantity.']]);
        }
        $count = BigDecimal::of($converted)->dividedBy($price->package_quantity, 0, RoundingMode::CEILING);
        $cost = $count->multipliedBy($price->package_price);
        if ($count->isGreaterThan('4294967295') || $cost->isGreaterThan('999999999999.9999')) {
            throw ValidationException::withMessages([$field => ['The purchase estimate exceeds the supported range.']]);
        }

        return ['availability_status' => 'available', 'price_id' => $price->id, 'package_quantity' => $price->package_quantity,
            'package_unit_code' => $price->package_unit_code, 'package_count' => (int) (string) $count,
            'package_price' => $price->package_price, 'purchase_quantity' => $this->decimal((string) $count->multipliedBy($price->package_quantity)),
            'estimated_cost' => $this->decimal((string) $cost), 'currency_code' => $currency, 'manual_estimated_cost' => null,
            'pricing_date' => today()->toDateString(), 'price' => $this->regionalData($price, $regions)];
    }

    private function fertilizerQuery(FarmCropCycle $cycle): Builder
    {
        $conditions = Arr::only($cycle->recommendationConditions(), ['crop_id', 'crop_variety_id', 'season_id', 'irrigation_condition', 'soil_condition']);
        $conditions['application_method'] = null;

        return Region::applyAgriculturalContext(FertilizerRecommendation::query(), $cycle->farm->regionContext()['region_ids'], $conditions)
            ->whereIn('recommendation_type', FertilizerRecommendation::RECOMMENDATION_TYPES)->whereHas('nutrients')->with('nutrients');
    }

    private function fertilizerRecommendation(FarmCropCycle $cycle): array
    {
        $regions = $cycle->farm->regionContext()['region_ids'];
        $selection = $this->pickRecommendation($this->fertilizerQuery($cycle), $regions, ['crop_variety_id', 'season_id', 'irrigation_condition', 'soil_condition', 'application_method']);
        $record = $selection['selected'];
        $nutrients = [];
        if ($record !== null) {
            foreach ($record->nutrients as $nutrient) {
                $area = AreaHelper::fromSquareMeters($cycle->squareMetres(), $nutrient->area_unit_code);
                $supported = in_array($nutrient->nutrient_code, FertilizerNutrient::CODES, true)
                    && in_array($nutrient->unit_code, FertilizerRecommendationNutrient::unitCodes(), true)
                    && $area !== null;
                $nutrients[] = [...$nutrient->only(['nutrient_code', 'recommended_quantity', 'unit_code', 'area_unit_code']),
                    'total_quantity' => $supported ? $this->decimal((string) BigDecimal::of($area)->multipliedBy($nutrient->recommended_quantity)) : null,
                    'availability_status' => $supported ? 'available' : ($cycle->cultivated_area === null ? 'insufficient_data' : 'unsupported_unit_conversion')];
            }
        }
        $codes = array_column($nutrients, 'nutrient_code');
        $products = Fertilizer::query()->published()->with(['translations', 'images', 'nutrients'])
            ->whereHas('nutrients', fn (Builder $query): Builder => $query->whereIn('nutrient_code', $codes))->orderBy('fertilizers.id')->limit(20)->get();

        return ['availability_status' => $selection['availability_status'], 'recommendation' => $record === null ? null : $this->regionalData($record, $regions),
            'alternatives' => $selection['alternatives'], 'nutrient_requirements' => $nutrients,
            'compatible_products' => FertilizerResource::collection($products)->resolve(), 'suggested_product_quantities' => null,
            'recommendation_status' => 'manual_product_quantities_required',
            'soil_interpretation_status' => 'laboratory_interpretation_unavailable', 'timeline_generated' => false];
    }

    private function yieldEstimate(FarmCropCycle $cycle): array
    {
        $regions = $cycle->farm->regionContext()['region_ids'];
        $conditions = Arr::only($cycle->recommendationConditions(), ['crop_id', 'crop_variety_id', 'season_id']);
        $query = Region::applyAgriculturalContext(CropYieldBenchmark::query(), $regions, $conditions)->whereIn('benchmark_type', CropYieldBenchmark::BENCHMARK_TYPES)->where('yield_avg_kg_ha', '>', 0);
        $selection = $this->pickRecommendation($query, $regions, ['crop_variety_id', 'season_id'], true);
        $record = $selection['selected'];
        $result = ['availability_status' => $selection['availability_status'], 'benchmark' => $record === null ? null : $this->regionalData($record, $regions),
            'alternatives' => $selection['alternatives'], 'estimated_min_yield' => null, 'estimated_avg_yield' => null, 'estimated_max_yield' => null,
            'yield_unit' => 'kg', 'display_unit' => 'quintal', 'display_estimates' => null];
        $hectares = AreaHelper::fromSquareMeters($cycle->squareMetres(), 'hectare');
        if ($record === null || $hectares === null) {
            if ($record !== null) {
                $result['availability_status'] = 'insufficient_data';
            }

            return $result;
        }
        foreach (['yield_min_kg_ha' => 'estimated_min_yield', 'yield_avg_kg_ha' => 'estimated_avg_yield', 'yield_max_kg_ha' => 'estimated_max_yield'] as $field => $output) {
            $result[$output] = $record->{$field} === null ? null : $this->decimal((string) BigDecimal::of($hectares)->multipliedBy($record->{$field}));
            $result['display_estimates'][$output] = $result[$output] === null ? null : FarmCropCycle::convertQuantity($result[$output], 'kg', 'quintal');
        }

        return $result;
    }

    private function expenses(FarmCropCycle $cycle, array $state): array
    {
        $seed = ($state['wizard_progress']['seed_sowing'] ?? null) === 'completed' ? ($state['planning_snapshot']['seed']['purchase']['estimated_cost'] ?? null) : null;
        $mode = $state['planning_snapshot']['soil']['assessment_mode'] ?? null;
        $declaredSoilCost = $state['planning_snapshot']['soil']['estimated_cost'] ?? null;
        $soil = $mode === 'basic' ? ($declaredSoilCost ?? '0') : ($mode === 'soil_test' ? $declaredSoilCost : null);
        $fertilizer = null;
        if (($state['wizard_progress']['fertilizer_planning'] ?? null) === 'completed' && $state['fertilizer_plans'] !== []) {
            $fertilizer = BigDecimal::zero();
            foreach ($state['fertilizer_plans'] as $row) {
                if (($row['estimated_cost'] ?? null) === null || ($row['currency_code'] ?? null) !== config('seed_prices.default_currency')) {
                    $fertilizer = null;
                    break;
                }
                $fertilizer = $fertilizer->plus($row['estimated_cost']);
            }
            $fertilizer = $fertilizer === null ? null : (string) $fertilizer;
        }
        $values = ['estimated_seed_cost' => $seed, 'estimated_soil_test_cost' => $soil, 'estimated_fertilizer_cost' => $fertilizer];
        $known = BigDecimal::zero();
        $missing = [];
        $components = [];
        foreach ($values as $name => $value) {
            if ($value === null) {
                $missing[] = $name;
                $components[$name] = str_contains($name, 'soil') && $mode === 'skipped'
                    || str_contains($name, 'fertilizer') && ($state['wizard_progress']['fertilizer_planning'] ?? null) === 'skipped' ? 'skipped' : 'unknown';
            } else {
                $known = $known->plus($value);
                $components[$name] = $name === 'estimated_soil_test_cost' && $mode === 'basic' && $declaredSoilCost === null ? 'not_applicable'
                    : (BigDecimal::of($value)->isZero() ? 'explicit_zero' : 'known');
                $values[$name] = $this->decimal($value, 2);
            }
        }

        return [...$values, 'known_subtotal' => $this->decimal((string) $known, 2),
            'total_estimated_cost' => $missing === [] ? $this->decimal((string) $known, 2) : null,
            'currency_code' => config('seed_prices.default_currency'), 'is_complete' => $missing === [], 'missing_components' => $missing, 'component_states' => $components];
    }

    /** @return array<string, list<string>> */
    private function validationIssues(FarmCropCycle $cycle, array $state, bool $checkAllocation = true): array
    {
        $issues = [];
        foreach (FarmCropCycle::STEPS as $step) {
            if (! in_array($state['wizard_progress'][$step] ?? 'pending', ['completed', 'skipped'], true)) {
                $issues['wizard_progress.'.$step] = ['Complete or review this step before activating the crop.'];
            }
        }
        if (($state['planning_snapshot']['context_fingerprint'] ?? null) !== $this->contextFingerprint($cycle->farm)) {
            $issues['wizard_progress.seed_sowing'] = ['The farm regional or agricultural context changed. Review the seed and fertilizer steps.'];
            $issues['wizard_progress.fertilizer_planning'] = $issues['wizard_progress.seed_sowing'];
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
        $seed = null;
        try {
            if ($cycle->seed_id !== null) {
                $seed = $this->compatibleSeed($cycle, $cycle->seed_id);
            }
            if ($cycle->seed_source === 'catalogue' && $seed === null) {
                $issues['seed_id'] = ['Select an eligible catalogue seed.'];
            }
            if ($cycle->seed_lot_id !== null && $seed !== null) {
                $this->eligibleLot($seed->id, $cycle->seed_lot_id);
            }
            $saved = $state['planning_snapshot']['seed'] ?? [];
            if (($saved['seed_rate']['id'] ?? null) !== null) {
                $current = $this->seedRecommendation($cycle, $seed);
                if (($current['seed_rate']['record_fingerprint'] ?? null) !== ($saved['seed_rate']['record_fingerprint'] ?? null)
                    || ($saved['quantity_mode'] ?? null) === 'suggested' && $current['recommended_quantity'] !== ($saved['recommended_quantity'] ?? null)) {
                    $issues['wizard_progress.seed_sowing'] = ['The saved seed recommendation changed or is no longer applicable. Review Step 3.'];
                }
            }
            if (($saved['purchase']['price_id'] ?? null) !== null) {
                $currentPrice = $this->purchaseEstimate($cycle, $cycle->seed_id, $saved['purchase']['price_id'], $cycle->seed_quantity, $cycle->seed_quantity_unit, null, 'seed', $saved['purchase']['currency_code']);
                if ($currentPrice['price']['record_fingerprint'] !== ($saved['purchase']['price']['record_fingerprint'] ?? null)) {
                    $issues['wizard_progress.seed_sowing'] = ['The selected seed package price changed. Review Step 3.'];
                }
            }
            if (($saved['maturity'] ?? null) !== null && $saved['maturity'] !== $this->harvestEstimate($cycle)) {
                $issues['wizard_progress.seed_sowing'] = ['Maturity information changed. Review the sowing and harvest estimates.'];
            }
        } catch (ValidationException $exception) {
            $issues['wizard_progress.seed_sowing'] = ['Saved seed or price information is no longer eligible. Review Step 3.'];
            $issues = [...$issues, ...$exception->errors()];
        }
        foreach ($state['fertilizer_plans'] as $index => $row) {
            try {
                if (isset($row['fertilizer_id']) && ! Fertilizer::query()->published()->whereKey($row['fertilizer_id'])->exists()) {
                    $issues['fertilizers.'.$index.'.fertilizer_id'] = ['The selected fertilizer is no longer eligible.'];
                }
                $savedRecommendation = $row['calculation_snapshot']['recommendation'] ?? null;
                if (isset($row['fertilizer_recommendation_id'])) {
                    $current = $this->fertilizerQuery($cycle)->find($row['fertilizer_recommendation_id']);
                    if ($current === null || $this->recordFingerprint($current) !== ($savedRecommendation['record_fingerprint'] ?? null)) {
                        $issues['wizard_progress.fertilizer_planning'] = ['A saved fertilizer recommendation changed or is no longer applicable. Review Step 5.'];
                    }
                }
                if (isset($row['fertilizer_price_id'])) {
                    $purchase = $this->purchaseEstimate($cycle, $row['fertilizer_id'], $row['fertilizer_price_id'], $row['planned_quantity'],
                        $row['quantity_unit_code'], null, 'fertilizer', $row['currency_code']);
                    if ($purchase['price']['record_fingerprint'] !== ($row['calculation_snapshot']['purchase']['price']['record_fingerprint'] ?? null)) {
                        $issues['wizard_progress.fertilizer_planning'] = ['A selected fertilizer price changed. Review Step 5.'];
                    }
                }
            } catch (ValidationException $exception) {
                $issues['wizard_progress.fertilizer_planning'] = ['A saved fertilizer price is no longer applicable. Review Step 5.'];
                foreach ($exception->errors() as $field => $errors) {
                    $issues['fertilizers.'.$index.'.'.$field] = $errors;
                }
            }
        }
        $soilMode = $state['planning_snapshot']['soil']['assessment_mode'] ?? null;
        if ($soilMode === 'soil_test') {
            $test = $state['soil_test'];
            $hasValues = $test !== null && Arr::where(Arr::only($test, SoilTest::MEASUREMENTS), fn ($value): bool => $value !== null) !== [];
            $hasReport = ! empty($test['report_file']) && Storage::disk(config('farm_crops.soil_reports_disk'))->exists($test['report_file']);
            if (! $hasValues && ! $hasReport) {
                $issues['soil_test'] = ['The soil report is missing. Enter measurements, upload a report or skip assessment.'];
            }
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
        $cycle->load(['crop.translations', 'crop.category.translations', 'cropVariety.translations', 'season.translations',
            'seed.translations', 'seed.images', 'seed.crop.translations', 'seed.cropVariety.translations', 'seedLot']);
        $state = $this->state($cycle);
        $historical = $cycle->status !== 'draft' && $cycle->editDraft === null;
        $issues = $historical ? [] : $this->validationIssues($cycle, $state);
        $progress = $state['wizard_progress'];
        foreach (array_keys($issues) as $key) {
            $step = match (true) {
                str_starts_with($key, 'wizard_progress.') => substr($key, strlen('wizard_progress.')),
                in_array($key, ['farm_id', 'crop_id', 'crop_variety_id', 'season_id'], true) => 'crop_selection',
                in_array($key, ['boundary', 'cultivated_area', 'area_unit'], true) => 'boundary',
                str_starts_with($key, 'seed') || $key === 'sowing_date' => 'seed_sowing',
                str_starts_with($key, 'fertilizers') => 'fertilizer_planning',
                str_starts_with($key, 'soil') => 'soil_assessment',
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
        $seedPlan = $state['planning_snapshot']['seed'] ?? [];
        $seedSowing = ['seed' => $cycle->seed === null ? null : new SeedResource($cycle->seed),
            'seed_lot' => $cycle->seedLot === null ? null : new SeedLotResource($cycle->seedLot),
            ...$cycle->only(['seed_source', 'seed_quantity', 'seed_quantity_unit', 'seed_notes']), ...$seedPlan,
            'sowing_date' => $cycle->sowing_date?->toDateString()];
        $soilTest = $state['soil_test'] === null ? null : Arr::except($state['soil_test'], ['report_file', 'verified_by', 'verified_at']);
        if ($soilTest !== null) {
            $soilTest['verification_status'] = 'pending';
            $soilTest['report_available'] = ! empty($state['soil_test']['report_file']);
            $soilTest['report_url'] = $soilTest['report_available'] ? route('account.farm-crops.current-soil-report', ['id' => $cycle->id]) : null;
        }
        $products = Fertilizer::query()->with(['translations', 'images', 'nutrients'])
            ->whereIn('id', array_filter(array_column($state['fertilizer_plans'], 'fertilizer_id')))->get()->keyBy('id');
        $plans = array_map(function (array $row) use ($products): array {
            $product = isset($row['fertilizer_id']) ? $products->get($row['fertilizer_id']) : null;

            return [...$row, 'fertilizer' => $product === null ? null : new FertilizerResource($product)];
        }, $state['fertilizer_plans']);
        $overview = $this->boundaryOverview($cycle->farm, $cycle->id);
        $boundary = ['boundary' => $state['boundary'], ...$cycle->only(FarmCropCycle::BOUNDARY_FIELDS),
            'calculated_area_sqm' => $sqm, 'calculated_area_ha' => AreaHelper::fromSquareMeters($sqm, 'hectare'),
            'farm_boundary' => $cycle->farm->boundary_geojson, 'existing_crop_boundaries' => $overview['existing_active_crops'],
            'farm_area_statistics' => Arr::only($overview, ['recorded_farm_area', 'measured_farm_area', 'existing_crop_area', 'remaining_area', 'can_use_full_farm_boundary'])];
        $soil = ['assessment_mode' => $state['planning_snapshot']['soil']['assessment_mode'] ?? null,
            'basic_assessment' => $state['soil_assessment'], 'soil_test' => $soilTest,
            'estimated_soil_test_cost' => $state['planning_snapshot']['soil']['estimated_cost'] ?? null];
        $state['wizard_progress'] = $progress;
        $currentSeed = ($progress['seed_sowing'] ?? null) === 'completed';
        $seedSowing['calculations_current'] = $historical || $currentSeed;
        if (! $historical && ! $currentSeed) {
            $seedSowing['availability_status'] = 'needs_review';
            if (isset($seedSowing['purchase'])) {
                $seedSowing['purchase']['availability_status'] = 'needs_review';
            }
        }
        $expenses = $historical ? ($state['planning_snapshot']['expenses'] ?? $this->expenses($cycle, $state)) : $this->expenses($cycle, $state);
        $yield = $historical ? ($state['planning_snapshot']['expected_yield'] ?? null) : $this->yieldEstimate($cycle);
        $fertilizer = ['recommendation_details' => $state['planning_snapshot']['fertilizer'] ?? null, 'selected_fertilizers' => $plans,
            'estimated_fertilizer_cost' => $expenses['estimated_fertilizer_cost'] ?? null,
            'skipped' => ($state['wizard_progress']['fertilizer_planning'] ?? null) === 'skipped'];
        $cycle->setAttribute('detail_data', [
            'crop_cycle_id' => $cycle->id, 'regional_context' => $context, 'farm_region' => $context['applicable_regions'][0] ?? null,
            'applicable_regions' => $context['applicable_regions'], 'regional_context_available' => $context['regional_context_available'],
            'boundary_details' => $boundary, 'seed_sowing_details' => $seedSowing, 'soil_assessment' => $soil, 'fertilizer_plan' => $fertilizer,
            'expenses' => $expenses, 'expected_yield' => $yield,
            'validation_issues' => (object) $issues, 'can_finalize' => ! $historical && $issues === [],
            'has_pending_edit' => $cycle->editDraft !== null, 'timeline_generated' => false,
            'estimates_are_historical' => $historical, 'accepted_regional_context' => $historical ? ($state['planning_snapshot']['region_context'] ?? null) : null,
            'saved_step_data' => ['crop_selection' => $cycle->only(FarmCropCycle::SELECTION_FIELDS), 'boundary' => $boundary,
                'seed_sowing' => $seedSowing, 'soil_assessment' => $soil, 'fertilizer_planning' => $fertilizer],
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

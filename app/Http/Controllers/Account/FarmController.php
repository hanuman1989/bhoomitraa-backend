<?php

namespace App\Http\Controllers\Account;

use App\Helpers\AreaHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreFarmRequest;
use App\Http\Requests\Account\UpdateFarmBoundaryRequest;
use App\Http\Requests\Account\UpdateFarmDetailsRequest;
use App\Http\Requests\Account\UpdateFarmRequest;
use App\Http\Requests\Account\UpdateFarmStatusRequest;
use App\Http\Resources\FarmResource;
use App\Models\Farm;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;

class FarmController extends Controller
{
    protected function databaseFailure(QueryException|PDOException $exception, string $message): JsonResponse
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return parent::databaseFailure($exception, $message);
        }
        Log::error('Farm database operation failed.', [
            'exception_type' => $exception::class, 'sql_state' => (string) $exception->getCode(),
        ]);

        return response()->json(['status' => false, 'data' => null, 'pagination' => null, 'message' => $message], 500);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'include_boundary' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = $request->user('user')->farms()->forApi($request->boolean('include_boundary'));
            if (($filters['status'] ?? 'all') !== 'all') {
                $query->where('farms.is_active', $filters['status'] === 'active');
            }
            $search = trim($filters['search'] ?? '');
            if ($search !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw("farms.name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("farms.village LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("farms.address LIKE ? ESCAPE '!'", [$pattern]);
                    foreach (['state', 'district', 'tehsil'] as $relation) {
                        $query->orWhereHas($relation, fn (Builder $region): Builder => $region->withLocalizedName()
                            ->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern]));
                    }
                });
            }
            $farms = $query->orderBy('farms.name')->orderBy('farms.id')
                ->paginate($request->integer('per_page', 15))->appends(Arr::only($filters, ['search', 'status', 'include_boundary', 'per_page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve farms. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => FarmResource::collection($farms),
            'pagination' => [
                'current_page' => $farms->currentPage(), 'last_page' => $farms->lastPage(), 'per_page' => $farms->perPage(),
                'total' => $farms->total(), 'from' => $farms->firstItem(), 'to' => $farms->lastItem(),
                'next_page_url' => $farms->nextPageUrl(), 'prev_page_url' => $farms->previousPageUrl(),
            ],
            'message' => 'Farms retrieved successfully.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $counts = $request->user('user')->farms()->selectRaw('COUNT(*) AS total_farms')
                ->selectRaw('COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) AS active_farms')
                ->selectRaw('COALESCE(SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END), 0) AS inactive_farms')->first();
            $preference = $request->user('user')->farmerProfile()->value('preferred_units');
            $preferredUnit = AreaHelper::canonicalUnit($preference);
            $unit = $preferredUnit ?? config('area_units.default_unit');
            $groups = $request->user('user')->farms()->select('area_unit')
                ->selectRaw('SUM(area) AS area_total, COUNT(*) AS farms_count')->groupBy('area_unit')
                ->orderBy('area_unit')->get();
            $total = BigDecimal::zero();
            $unresolved = 0;
            $totals = [];
            foreach ($groups as $group) {
                $squareMetres = AreaHelper::toSquareMeters((string) $group->area_total, $group->area_unit);
                if ($squareMetres === null) {
                    $unresolved += (int) $group->farms_count;
                } else {
                    $total = $total->plus($squareMetres);
                }
                $totals[] = ['value' => (string) BigDecimal::of((string) $group->area_total)->toScale(3, RoundingMode::HALF_UP),
                    'unit' => $group->area_unit, 'conversion_available' => $squareMetres !== null];
            }
            $converted = $unresolved === 0 ? AreaHelper::fromSquareMeters((string) $total, $unit) : null;
            $available = $converted !== null;
            $squareMetres = $available ? (string) $total->toScale(6, RoundingMode::HALF_UP) : null;
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve farm summary. Please try again later.');
        }

        return response()->json([
            'status' => true,
            'data' => [
                'total_farms' => (int) $counts->total_farms, 'active_farms' => (int) $counts->active_farms,
                'inactive_farms' => (int) $counts->inactive_farms,
                'total_land_area' => [
                    'value' => $available ? (string) BigDecimal::of($converted)->toScale(3, RoundingMode::HALF_UP) : null,
                    'unit' => $unit, 'area_sqm' => $squareMetres, 'basis' => 'government_record',
                    'conversion_available' => $available, 'unresolved_farms_count' => $unresolved,
                    'preference_resolution_required' => $preference !== null && $preferredUnit === null,
                    'breakdown' => $totals,
                ],
                'total_land_area_sqm' => $squareMetres,
                'area_totals' => $totals,
                'area_conversion_unavailable' => ! $available,
            ],
            'pagination' => null, 'message' => 'Farm summary retrieved successfully.',
        ]);
    }

    public function store(StoreFarmRequest $request): JsonResponse
    {
        $input = array_replace(array_fill_keys([...Farm::BASIC_FIELDS, 'boundary', 'boundary_source', 'gps_accuracy_m'], null),
            $request->safe()->only([...Farm::BASIC_FIELDS, 'boundary', 'boundary_source', 'gps_accuracy_m']));
        $input['area'] = (string) BigDecimal::of($input['area'])->toScale(3);
        foreach (['state_id', 'district_id', 'tehsil_id'] as $field) {
            if ($input[$field] !== null) {
                $input[$field] = (int) $input[$field];
            }
        }
        foreach (['latitude', 'longitude'] as $field) {
            if ($input[$field] !== null) {
                $input[$field] = number_format((float) $input[$field], 7, '.', '');
            }
        }
        if ($input['gps_accuracy_m'] !== null) {
            $input['gps_accuracy_m'] = (string) BigDecimal::of($input['gps_accuracy_m'])->toScale(2);
        }
        if ($input['boundary'] !== null) {
            $input['boundary'] = Farm::normalizeBoundary($input['boundary']);
        }
        ksort($input);
        $key = hash('sha256', $request->validated('idempotency_key'));
        $fingerprint = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
        try {
            $metrics = $input['boundary'] === null ? null : Farm::boundaryMetrics($input['boundary']);
            $farm = DB::transaction(function () use ($request, $input, $key, $fingerprint, $metrics): Farm|JsonResponse {
                $user = User::query()->lockForUpdate()->findOrFail($request->user('user')->getKey());
                abort_unless($user->status, 403, 'User account is inactive.');
                $existing = $user->farms()->where('creation_key', $key)->first();
                if ($existing !== null && $existing->creation_expires_at?->isFuture()) {
                    if (! hash_equals($existing->creation_payload_hash, $fingerprint)) {
                        return response()->json(['status' => false, 'data' => null, 'pagination' => null,
                            'message' => 'The Idempotency-Key has already been used with a different farm payload.'], 409);
                    }

                    return $user->farms()->forApi(true)->findOrFail($existing->id);
                }
                if ($existing !== null) {
                    $existing->timestamps = false;
                    $existing->forceFill(['creation_key' => null, 'creation_payload_hash' => null, 'creation_expires_at' => null])->save();
                }
                $farm = $user->farms()->make(Arr::only($input, Farm::BASIC_FIELDS));
                $errors = $farm->locationErrors(lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                $farm->region_id = $farm->tehsil_id ?? $farm->district_id;
                $farm->forceFill(['creation_key' => $key, 'creation_payload_hash' => $fingerprint,
                    'creation_expires_at' => now()->addHours(config('farms.creation_idempotency_hours'))]);
                $farm->save();
                if ($input['boundary'] !== null) {
                    $farm->persistBoundary($input['boundary'], $metrics, $input['boundary_source'], $input['gps_accuracy_m']);
                }

                return $user->farms()->forApi(true)->findOrFail($farm->id);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the farm. Please try again later.');
        }

        if ($farm instanceof JsonResponse) {
            return $farm;
        }

        return response()->json(['status' => true, 'data' => new FarmResource($farm), 'pagination' => null,
            'message' => 'Farm created successfully.'], 201);
    }

    public function show(Request $request, string $farm): JsonResponse
    {
        $farm = $request->user('user')->farms()->forApi(true)->findOrFail($farm);

        return response()->json(['status' => true, 'data' => new FarmResource($farm), 'pagination' => null,
            'message' => 'Farm retrieved successfully.']);
    }

    public function update(UpdateFarmRequest $request, string $farm): JsonResponse
    {
        $attributes = $request->safe()->only(Farm::BASIC_FIELDS);
        try {
            $farm = DB::transaction(function () use ($request, $farm, $attributes): Farm {
                $record = $request->user('user')->farms()->lockForUpdate()->findOrFail($farm);
                if (array_key_exists('area_unit', $attributes) && ! array_key_exists('area', $attributes)
                    && $attributes['area_unit'] !== $record->area_unit) {
                    $converted = AreaHelper::convert($record->area, $record->area_unit, $attributes['area_unit']);
                    $converted = $converted === null ? null : BigDecimal::of($converted)->toScale(3, RoundingMode::HALF_UP);
                    if ($converted === null || $converted->isLessThanOrEqualTo(0) || $converted->isGreaterThan('999999999.999')) {
                        throw ValidationException::withMessages(['area' => ['Supply a corrected area and unit; the existing measurement cannot be converted within the supported precision.']]);
                    }
                    $attributes['area'] = (string) $converted;
                }
                $record->fill($attributes);
                $errors = $record->locationErrors(lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
                if ($record->isDirty(['state_id', 'district_id', 'tehsil_id'])) {
                    $record->region_id = $record->tehsil_id ?? $record->district_id;
                }
                if ($record->isDirty('area_unit') && $record->actual_area !== null) {
                    $squareMetres = $record->actual_area_sqm;
                    if ($record->getRawOriginal('boundary') !== null) {
                        $boundary = $request->user('user')->farms()->forApi(true)->findOrFail($farm)->boundary_geojson;
                        $squareMetres = Farm::boundaryMetrics(Farm::normalizeBoundary($boundary))['square_metres'];
                    }
                    if ($squareMetres === null) {
                        throw ValidationException::withMessages(['area_unit' => ['Update the boundary to resolve the existing actual area before changing units.']]);
                    }
                    $record->forceFill($record->calculatedAreaAttributes($squareMetres));
                }
                $contextChanged = $record->isDirty(['state_id', 'district_id', 'tehsil_id', 'region_id']);
                $record->save();
                if ($contextChanged) {
                    $record->invalidateCropDrafts();
                }

                return $request->user('user')->farms()->forApi(true)->findOrFail($farm);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the farm. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FarmResource($farm), 'pagination' => null,
            'message' => 'Farm updated successfully.']);
    }

    public function updateAdditionalDetails(UpdateFarmDetailsRequest $request, string $farm): JsonResponse
    {
        try {
            $record = DB::transaction(function () use ($request, $farm): Farm {
                $record = $request->user('user')->farms()->lockForUpdate()->findOrFail($farm);
                $record->fill($request->safe()->only(Farm::DETAIL_FIELDS));
                $contextChanged = $record->isDirty(['soil_type', 'land_type', 'water_availability', 'irrigation_source']);
                $record->save();
                if ($contextChanged) {
                    $record->invalidateCropDrafts();
                }

                return $request->user('user')->farms()->forApi(true)->findOrFail($farm);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update farm details. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FarmResource($record), 'pagination' => null,
            'message' => 'Farm details updated successfully.']);
    }

    public function updateBoundary(UpdateFarmBoundaryRequest $request, string $farm): JsonResponse
    {
        $input = $request->validated();
        $boundary = $input['boundary'] === null ? null : Farm::normalizeBoundary($input['boundary']);
        try {
            $metrics = $boundary === null ? null : Farm::boundaryMetrics($boundary);
            $record = DB::transaction(function () use ($request, $farm, $input, $boundary, $metrics): Farm {
                $record = $request->user('user')->farms()->lockForUpdate()->findOrFail($farm);
                $record->validateActiveCropBoundary($boundary);
                $record->persistBoundary($boundary, $metrics, $input['boundary_source'] ?? null, $input['gps_accuracy_m'] ?? null);

                return $request->user('user')->farms()->forApi(true)->findOrFail($farm);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the farm boundary. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FarmResource($record), 'pagination' => null,
            'message' => 'Farm boundary updated successfully.']);
    }

    public function updateStatus(UpdateFarmStatusRequest $request, string $farm): JsonResponse
    {
        try {
            $record = DB::transaction(function () use ($request, $farm): Farm {
                $record = $request->user('user')->farms()->lockForUpdate()->findOrFail($farm);
                if (! $request->boolean('is_active') && $record->cropCycles()->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['is_active' => ['Cancel active crops before deactivating the farm.']]);
                }
                $record->is_active = $request->boolean('is_active');
                $record->save();

                return $request->user('user')->farms()->forApi(true)->findOrFail($farm);
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update farm status. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => new FarmResource($record), 'pagination' => null,
            'message' => 'Farm status updated successfully.']);
    }
}

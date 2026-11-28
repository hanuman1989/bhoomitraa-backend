<?php

namespace App\Http\Controllers\Account;

use App\Enums\IrrigationAssetStatus;
use App\Enums\IrrigationAssetType;
use App\Enums\IrrigationOwnershipType;
use App\Enums\IrrigationPipelineType;
use App\Enums\IrrigationSourceType;
use App\Enums\IrrigationWaterAvailability;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreIrrigationAssetRequest;
use App\Http\Requests\Account\UpdateIrrigationAssetRequest;
use App\Http\Resources\Account\IrrigationAssetMapResource;
use App\Http\Resources\Account\IrrigationAssetResource;
use App\Services\Irrigation\IrrigationAssetService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use PDOException;

/**
 * A farmer's water sources and pipelines. Every query starts from the farmer's
 * irrigationAssets() relation, so another farmer's id is not found (404).
 */
class IrrigationAssetController extends Controller
{
    public function __construct(private readonly IrrigationAssetService $assets) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request, true);
        try {
            $assets = $request->user('user')->irrigationAssets()->withGeoJson()->applyFilters($filters)
                ->orderByDesc('irrigation_assets.updated_at')->orderByDesc('irrigation_assets.id')
                ->paginate($request->integer('per_page', (int) config('irrigation.per_page')))
                ->appends(array_filter(Arr::except($filters, 'page'), fn (mixed $value): bool => $value !== null));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.load_failed'));
        }

        return response()->json([
            'status' => true, 'data' => IrrigationAssetResource::collection($assets),
            'pagination' => [
                'current_page' => $assets->currentPage(), 'last_page' => $assets->lastPage(), 'per_page' => $assets->perPage(),
                'total' => $assets->total(), 'from' => $assets->firstItem(), 'to' => $assets->lastItem(),
                'next_page_url' => $assets->nextPageUrl(), 'prev_page_url' => $assets->previousPageUrl(),
            ],
            'message' => __('irrigation.messages.listed'),
        ]);
    }

    /** Unfiltered totals; inactive assets count, deleted ones don't. */
    public function summary(Request $request): JsonResponse
    {
        try {
            $summary = $this->assets->summary($request->user('user'));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.load_failed'));
        }

        return response()->json(['status' => true, 'data' => $summary, 'pagination' => null, 'message' => __('irrigation.messages.summary')]);
    }

    /** Mapped assets matching the list filters, as one GeoJSON FeatureCollection without pagination. */
    public function map(Request $request): JsonResponse
    {
        $filters = $this->filters($request, false);
        try {
            $assets = $request->user('user')->irrigationAssets()->withGeoJson()->applyFilters($filters)
                ->whereNotNull('irrigation_assets.geometry')->orderBy('irrigation_assets.id')->get();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.load_failed'));
        }

        return response()->json([
            'status' => true,
            'data' => ['type' => 'FeatureCollection', 'features' => IrrigationAssetMapResource::collection($assets)],
            'pagination' => null, 'message' => __('irrigation.messages.map'),
        ]);
    }

    /** Every option key with its label in the request locale, and which source types record depth. */
    public function options(): JsonResponse
    {
        $options = fn (array $cases): array => array_map(fn (mixed $case): array => ['key' => $case->value, 'label' => $case->label()], $cases);

        return response()->json([
            'status' => true,
            'data' => [
                'asset_types' => $options(IrrigationAssetType::cases()),
                'source_types' => array_map(fn (IrrigationSourceType $type): array => ['key' => $type->value,
                    'label' => $type->label(), 'supports_depth' => $type->supportsDepth()], IrrigationSourceType::cases()),
                'pipeline_types' => $options(IrrigationPipelineType::cases()),
                'statuses' => $options(IrrigationAssetStatus::cases()),
                'ownership_types' => $options(IrrigationOwnershipType::cases()),
                'water_availability' => $options(IrrigationWaterAvailability::cases()),
            ],
            'pagination' => null, 'message' => __('irrigation.messages.options'),
        ]);
    }

    public function store(StoreIrrigationAssetRequest $request): JsonResponse
    {
        try {
            $asset = $this->assets->create($request->user('user'), $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.save_failed'));
        }

        return response()->json(['status' => true, 'data' => new IrrigationAssetResource($asset), 'pagination' => null,
            'message' => __('irrigation.messages.created')], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $asset = $request->user('user')->irrigationAssets()->withGeoJson()->findOrFail($id);

        return response()->json(['status' => true, 'data' => new IrrigationAssetResource($asset), 'pagination' => null,
            'message' => __('irrigation.messages.retrieved')]);
    }

    public function update(UpdateIrrigationAssetRequest $request, string $id): JsonResponse
    {
        try {
            $asset = $this->assets->update($request->user('user'), $id, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.save_failed'));
        }

        return response()->json(['status' => true, 'data' => new IrrigationAssetResource($asset), 'pagination' => null,
            'message' => __('irrigation.messages.updated')]);
    }

    /** Soft delete. Farms, crops and other assets are never touched. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $asset = $request->user('user')->irrigationAssets()->withGeoJson()->findOrFail($id);
        try {
            $asset->delete();
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, __('irrigation.messages.delete_failed'));
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => __('irrigation.messages.deleted')]);
    }

    /**
     * Validated list and map filters. A subtype must match the chosen group, and an asset
     * can't have both a source type and a pipeline type.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request, bool $paginated): array
    {
        $rules = [
            'asset_type' => ['sometimes', 'nullable', Rule::enum(IrrigationAssetType::class)],
            'source_type' => ['sometimes', 'nullable', Rule::enum(IrrigationSourceType::class), 'prohibited_if:asset_type,pipeline', 'prohibits:pipeline_type'],
            'pipeline_type' => ['sometimes', 'nullable', Rule::enum(IrrigationPipelineType::class), 'prohibited_if:asset_type,source'],
            'status' => ['sometimes', 'nullable', Rule::enum(IrrigationAssetStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
        if ($paginated) {
            $rules['page'] = ['sometimes', 'integer', 'min:1'];
            $rules['per_page'] = ['sometimes', 'integer', 'min:1', 'max:100'];
        }
        $messages = [
            'search.max' => __('irrigation.validation.search_max'),
            'source_type.prohibited_if' => __('irrigation.validation.filter_mismatch'),
            'source_type.prohibits' => __('irrigation.validation.filter_mismatch'),
            'pipeline_type.prohibited_if' => __('irrigation.validation.filter_mismatch'),
        ];
        foreach (['asset_type', 'source_type', 'pipeline_type', 'status'] as $field) {
            $messages[$field.'.'.Enum::class] = __('irrigation.validation.option_invalid');
        }

        return $request->validate($rules, $messages);
    }
}

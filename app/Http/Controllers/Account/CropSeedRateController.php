<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\RegionalRecordResource;
use App\Models\CropSeedRate;
use App\Models\FarmCropCycle;
use App\Models\Region;
use App\Models\Seed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CropSeedRateController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['id'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'farm_id' => ['required_without:crop_cycle_id', 'integer', 'min:1'], 'crop_cycle_id' => ['sometimes', 'integer', 'min:1'],
            'region_id' => ['sometimes', 'integer', 'min:1'],

            'crop_id' => ['required_without:crop_cycle_id', 'integer', 'min:1'],
            'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'season_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'seed_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'material_type' => ['sometimes', Rule::in(Seed::MATERIAL_TYPES)],
            'sowing_method' => ['sometimes', Rule::in(CropSeedRate::SOWING_METHODS)], 'irrigation_condition' => ['sometimes', Rule::in(CropSeedRate::IRRIGATION_CONDITIONS)],
            'rate_basis' => ['sometimes', Rule::in(CropSeedRate::RATE_BASES)],
        ]);
        $context = FarmCropCycle::catalogueContext((int) $request->user('user')->id, isset($filters['farm_id']) ? (int) $filters['farm_id'] : null,
            isset($filters['crop_cycle_id']) ? (int) $filters['crop_cycle_id'] : null, isset($filters['region_id']) ? (int) $filters['region_id'] : null);
        $conditions = [];
        foreach (['crop_id', 'crop_variety_id', 'seed_id', 'season_id', 'material_type', 'sowing_method', 'irrigation_condition'] as $field) {
            $conditions[$field] = $context['cycle'] !== null ? ($context['conditions'][$field] ?? null) : ($filters[$field] ?? $context['conditions'][$field] ?? null);
        }
        $seedId = $context['cycle']?->seed_id ?? $filters['seed_id'] ?? null;
        if ($seedId !== null) {
            $seed = Seed::query()->published()->where('crop_id', $conditions['crop_id'])->find($seedId);
            if ($seed === null || $seed->crop_variety_id !== null && $seed->crop_variety_id !== ($conditions['crop_variety_id'] === null ? null : (int) $conditions['crop_variety_id'])) {
                throw ValidationException::withMessages(['seed_id' => ['The seed must match the selected crop and variety.']]);
            }
            $conditions['seed_id'] = $seed->id;
            $conditions['material_type'] = $seed->material_type;
        }
        $query = Region::applyAgriculturalContext(CropSeedRate::query(), $context['region_context']['region_ids'], $conditions)
            ->whereHas('crop', fn (Builder $crop): Builder => $crop->published());
        $query->whereIn('rate_basis', CropSeedRate::RATE_BASES)->whereIn('rate_unit_code', Seed::UNIT_CODES)->whereIn('area_unit_code', CropSeedRate::AREA_UNIT_CODES)->where('rate_recommended', '>', 0);
        if (isset($filters['rate_basis'])) {
            $query->where('rate_basis', $filters['rate_basis']);
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('knowledgeSource', fn (Builder $source): Builder => $source->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("notes LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $items = $query->orderBy($filters['sort_by'] ?? 'id', $filters['sort_direction'] ?? 'asc')->orderBy('id')
            ->paginate($request->integer('per_page', 15))->appends($filters);
        $items->each(fn ($item) => $item->setAttribute('matching_region_ids', $context['region_context']['region_ids']));

        return response()->json(['status' => true, 'data' => RegionalRecordResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Applicable records retrieved successfully.']);
    }
}

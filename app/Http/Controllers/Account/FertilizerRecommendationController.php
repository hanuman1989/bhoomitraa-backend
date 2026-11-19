<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\RegionalRecordResource;
use App\Models\CropSeedRate;
use App\Models\FarmCropCycle;
use App\Models\FertilizerRecommendation;
use App\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FertilizerRecommendationController extends Controller
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
            'recommendation_type' => ['sometimes', Rule::in(FertilizerRecommendation::RECOMMENDATION_TYPES)],
            'irrigation_condition' => ['sometimes', Rule::in(CropSeedRate::IRRIGATION_CONDITIONS)],
            'soil_condition' => ['sometimes', Rule::in(FertilizerRecommendation::SOIL_CONDITIONS)],
            'application_method' => ['sometimes', Rule::in(FertilizerRecommendation::APPLICATION_METHODS)],
        ]);
        $context = FarmCropCycle::catalogueContext((int) $request->user('user')->id, isset($filters['farm_id']) ? (int) $filters['farm_id'] : null,
            isset($filters['crop_cycle_id']) ? (int) $filters['crop_cycle_id'] : null, isset($filters['region_id']) ? (int) $filters['region_id'] : null);
        $conditions = [];
        foreach (['crop_id', 'crop_variety_id', 'season_id', 'irrigation_condition', 'soil_condition', 'application_method'] as $field) {
            $conditions[$field] = $context['cycle'] !== null ? ($context['conditions'][$field] ?? null) : ($filters[$field] ?? $context['conditions'][$field] ?? null);
        }

        $query = Region::applyAgriculturalContext(FertilizerRecommendation::query(), $context['region_context']['region_ids'], $conditions)
            ->whereHas('crop', fn (Builder $crop): Builder => $crop->published());
        $query->whereIn('recommendation_type', FertilizerRecommendation::RECOMMENDATION_TYPES)->whereHas('nutrients')->with('nutrients');
        if (isset($filters['recommendation_type'])) {
            $query->where('recommendation_type', $filters['recommendation_type']);
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

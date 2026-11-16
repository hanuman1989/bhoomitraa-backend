<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\RegionalRecordResource;
use App\Models\FarmCropCycle;
use App\Models\Region;
use App\Models\Seed;
use App\Models\SeedPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeedPriceController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['id', 'package_price', 'package_quantity', 'seller_name'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'farm_id' => ['required_without:crop_cycle_id', 'integer', 'min:1'], 'crop_cycle_id' => ['sometimes', 'integer', 'min:1'],
            'region_id' => ['sometimes', 'integer', 'min:1'],
            'price_type' => ['sometimes', Rule::in(SeedPrice::PRICE_TYPES)],
            'package_unit_code' => ['sometimes', Rule::in(Seed::UNIT_CODES)],
            'currency_code' => ['sometimes', Rule::in(config('seed_prices.currencies'))],
            'seed_id' => ['required_without:crop_cycle_id', 'integer', 'min:1'],
        ]);
        $context = FarmCropCycle::catalogueContext((int) $request->user('user')->id, isset($filters['farm_id']) ? (int) $filters['farm_id'] : null,
            isset($filters['crop_cycle_id']) ? (int) $filters['crop_cycle_id'] : null, isset($filters['region_id']) ? (int) $filters['region_id'] : null);
        $productId = $context['cycle']?->seed_id ?? $filters['seed_id'] ?? null;
        $product = Seed::query()->published()->find($productId);
        if ($product === null) {
            throw ValidationException::withMessages(['seed_id' => ['Select an eligible product.']]);
        }
        if ($context['cycle'] !== null && ($product->crop_id !== $context['cycle']->crop_id
            || $product->crop_variety_id !== null && $product->crop_variety_id !== $context['cycle']->crop_variety_id)) {
            throw ValidationException::withMessages(['seed_id' => ['The seed must match the saved crop and variety.']]);
        }
        $query = Region::applyPlanningPrices(SeedPrice::query(), $context['region_context']['region_ids'], today()->toDateString(), $filters['currency_code'] ?? config('seed_prices.default_currency'))
            ->where('seed_id', $product->id)->whereIn('package_unit_code', $product->compatibleUnits());
        foreach (['price_type', 'package_unit_code'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('knowledgeSource', fn (Builder $source): Builder => $source->whereRaw("title LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("seller_name LIKE ? ESCAPE '!'", [$pattern]);
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

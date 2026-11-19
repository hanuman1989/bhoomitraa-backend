<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\SeedResource;
use App\Models\CropVariety;
use App\Models\Seed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeedController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'sort_by' => ['sometimes', Rule::in(['name', 'brand_name', 'id'])],
            'crop_id' => ['sometimes', 'integer', 'min:1'], 'crop_variety_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'seed_type' => ['sometimes', Rule::in(Seed::SEED_TYPES)], 'material_type' => ['sometimes', Rule::in(Seed::MATERIAL_TYPES)],
            'seed_class' => ['sometimes', Rule::in(Seed::SEED_CLASSES)], 'brand_name' => ['sometimes', 'string', 'max:150'],
            'producer_name' => ['sometimes', 'string', 'max:200'],
        ]);
        $query = Seed::query()->published()->withLocalizedName()->with(['images', 'crop.translations', 'crop.category.translations', 'cropVariety.translations']);
        $query->with('translations');
        foreach (['crop_id', 'seed_type', 'material_type', 'seed_class', 'brand_name', 'producer_name'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('seeds.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['crop_variety_id'])) {
            $variety = CropVariety::query()->published()->find($filters['crop_variety_id']);
            if ($variety === null || isset($filters['crop_id']) && $variety->crop_id !== (int) $filters['crop_id']) {
                throw ValidationException::withMessages(['crop_variety_id' => ['Select an eligible variety belonging to the crop.']]);
            }
            $query->where('seeds.crop_id', $variety->crop_id)
                ->where(fn (Builder $query): Builder => $query->whereNull('seeds.crop_variety_id')->orWhere('seeds.crop_variety_id', $variety->id));
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('translations', fn (Builder $translation): Builder => $translation->whereIn('locale', ['en', 'hi'])->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("seeds.sku LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("seeds.brand_name LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("seeds.producer_name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = ($filters['sort_by'] ?? 'name') === 'name' ? 'localized_name' : 'seeds.'.($filters['sort_by'] ?? 'name');
        $items = $query->orderBy($sort, $filters['sort_direction'] ?? 'asc')->orderBy('seeds.id')
            ->paginate($request->integer('per_page', 15))->appends($filters);

        return response()->json(['status' => true, 'data' => SeedResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Seed records retrieved successfully.']);
    }
}

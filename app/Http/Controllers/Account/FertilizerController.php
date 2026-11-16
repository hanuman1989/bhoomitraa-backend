<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\FertilizerResource;
use App\Models\Fertilizer;
use App\Models\FertilizerNutrient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FertilizerController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'sort_by' => ['sometimes', Rule::in(['name', 'brand_name', 'id'])],
            'fertilizer_type' => ['sometimes', Rule::in(Fertilizer::TYPES)], 'form' => ['sometimes', Rule::in(Fertilizer::FORMS)],
            'brand_name' => ['sometimes', 'string', 'max:150'], 'manufacturer_name' => ['sometimes', 'string', 'max:200'],
            'grade' => ['sometimes', 'string', 'max:50'], 'nutrient_code' => ['sometimes', Rule::in(FertilizerNutrient::CODES)],
        ]);
        $query = Fertilizer::query()->published()->withLocalizedName()->with(['translations', 'images', 'nutrients']);
        $query->with('translations');
        foreach (['fertilizer_type', 'form', 'brand_name', 'manufacturer_name', 'grade'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('fertilizers.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['nutrient_code'])) {
            $query->whereHas('nutrients', fn (Builder $nutrient): Builder => $nutrient->where('nutrient_code', $filters['nutrient_code']));
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('translations', fn (Builder $translation): Builder => $translation->whereIn('locale', ['en', 'hi'])->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("fertilizers.brand_name LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("fertilizers.manufacturer_name LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("fertilizers.grade LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("fertilizers.fertilizer_code LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = ($filters['sort_by'] ?? 'name') === 'name' ? 'localized_name' : 'fertilizers.'.($filters['sort_by'] ?? 'name');
        $items = $query->orderBy($sort, $filters['sort_direction'] ?? 'asc')->orderBy('fertilizers.id')
            ->paginate($request->integer('per_page', 15))->appends($filters);

        return response()->json(['status' => true, 'data' => FertilizerResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Fertilizer records retrieved successfully.']);
    }
}

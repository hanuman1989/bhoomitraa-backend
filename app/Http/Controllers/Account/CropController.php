<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\CropResource;
use App\Models\Crop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CropController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'sort_by' => ['sometimes', Rule::in(['name', 'crop_code', 'id'])],
            'category_id' => ['sometimes', 'integer', 'min:1'], 'season_id' => ['prohibited'],
            'is_recommendate' => ['sometimes', Rule::in(['true', 'false', '1', '0', true, false, 1, 0])],
        ]);
        $query = Crop::query()->published()->withCount(['varieties' => fn (Builder $variety): Builder => $variety->published()]);
        $query->with('translations');
        foreach (['category_id'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('crops.'.$field, $filters[$field]);
            }
        }
        if (array_key_exists('is_recommendate', $filters)) {
            $query->where('crops.is_recommendate', filter_var($filters['is_recommendate'], FILTER_VALIDATE_BOOLEAN));
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('translations', fn (Builder $translation): Builder => $translation->whereIn('locale', ['en', 'hi'])->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("crops.slug LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("crops.crop_code LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("crops.scientific_name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = ($filters['sort_by'] ?? 'name') === 'name' ? 'localized_name' : 'crops.'.($filters['sort_by'] ?? 'name');
        $items = $query->orderBy($sort, $filters['sort_direction'] ?? 'asc')->orderBy('crops.id')
            ->paginate($request->integer('per_page', 15))->appends($filters);

        return response()->json(['status' => true, 'data' => CropResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Crop records retrieved successfully.']);
    }
}

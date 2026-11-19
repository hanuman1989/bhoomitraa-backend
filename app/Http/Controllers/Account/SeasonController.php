<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\SeasonResource;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeasonController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'sort_by' => ['sometimes', Rule::in(['name', 'slug', 'id'])],

        ]);
        $query = Season::query()->published();
        $query->with('translations');
        foreach ([] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('seasons.'.$field, $filters[$field]);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereHas('translations', fn (Builder $translation): Builder => $translation->whereIn('locale', ['en', 'hi'])->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
                $query->orWhereRaw("seasons.slug LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = ($filters['sort_by'] ?? 'name') === 'name' ? 'localized_name' : 'seasons.'.($filters['sort_by'] ?? 'name');
        $items = $query->orderBy($sort, $filters['sort_direction'] ?? 'asc')->orderBy('seasons.id')
            ->paginate($request->integer('per_page', 15))->appends($filters);

        return response()->json(['status' => true, 'data' => SeasonResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Season records retrieved successfully.']);
    }
}

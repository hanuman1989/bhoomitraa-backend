<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\Account\SeedLotResource;
use App\Models\Seed;
use App\Models\SeedLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SeedLotController extends Controller
{
    public function getList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'sort_by' => ['sometimes', Rule::in(['lot_number', 'valid_until', 'id'])],
            'seed_id' => ['required', 'integer', 'min:1'], 'seed_class' => ['sometimes', Rule::in(Seed::SEED_CLASSES)],
        ]);
        $query = SeedLot::query()->selectable()->where('verification_status', 'verified')
            ->where(fn (Builder $query): Builder => $query->whereNull('valid_until')->orWhere('valid_until', '>=', today()->toDateString()))
            ->where(fn (Builder $query): Builder => $query->whereNull('production_date')->orWhere('production_date', '<=', today()->toDateString()))
            ->where(fn (Builder $query): Builder => $query->whereNull('test_date')->orWhere('test_date', '<=', today()->toDateString()));

        foreach (['seed_id', 'seed_class'] as $field) {
            if (array_key_exists($field, $filters)) {
                $query->where('seed_lots.'.$field, $filters[$field]);
            }
        }
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function (Builder $query) use ($pattern): void {

                $query->whereRaw("seed_lots.lot_number LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("seed_lots.certification_number LIKE ? ESCAPE '!'", [$pattern]);
                $query->orWhereRaw("seed_lots.certification_body LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = ($filters['sort_by'] ?? 'lot_number') === 'name' ? 'localized_name' : 'seed_lots.'.($filters['sort_by'] ?? 'lot_number');
        $items = $query->orderBy($sort, $filters['sort_direction'] ?? 'asc')->orderBy('seed_lots.id')
            ->paginate($request->integer('per_page', 15))->appends($filters);

        return response()->json(['status' => true, 'data' => SeedLotResource::collection($items),
            'pagination' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'from' => $items->firstItem(), 'to' => $items->lastItem(),
                'next_page_url' => $items->nextPageUrl(), 'prev_page_url' => $items->previousPageUrl()],
            'message' => 'Seed Lot records retrieved successfully.']);
    }
}

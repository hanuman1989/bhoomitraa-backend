<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSeedPriceRequest;
use App\Http\Requests\Admin\UpdateSeedPriceRequest;
use App\Http\Requests\Admin\UpdateSeedPriceStatusRequest;
use App\Http\Resources\Admin\SeedPriceResource;
use App\Models\Seed;
use App\Models\SeedPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDOException;

class SeedPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'keyword' => ['sometimes', 'nullable', 'string', 'max:150'],
            'seed_id' => ['sometimes', 'integer', 'min:1', Rule::exists('seeds', 'id')],
            'price_type' => ['sometimes', Rule::in(SeedPrice::PRICE_TYPES)],
            'region_scope' => ['sometimes', Rule::in(SeedPrice::REGION_SCOPES)],
            'is_active' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        try {
            $query = SeedPrice::query()->with($this->displayRelations());
            foreach (['seed_id', 'price_type', 'region_scope', 'is_active'] as $field) {
                if (array_key_exists($field, $filters)) {
                    $query->where($field, $filters[$field]);
                }
            }
            $keyword = trim($filters['keyword'] ?? '');
            if ($keyword !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword).'%';
                $query->where(fn (Builder $query): Builder => $query->whereRaw("seller_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('seed', fn (Builder $seed): Builder => $seed->withLocalizedName()
                        ->where(fn (Builder $seed): Builder => $seed->whereRaw("COALESCE(requested_translation.name, default_translation.name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("default_translation.name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("seeds.sku LIKE ? ESCAPE '!'", [$pattern]))));
            }
            $prices = $query->orderByDesc('effective_from')->orderBy('id')->paginate($request->integer('per_page', 15))->appends(Arr::except($filters, ['page']));
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to retrieve seed prices. Please try again later.');
        }

        return response()->json([
            'status' => true, 'data' => SeedPriceResource::collection($prices),
            'pagination' => ['current_page' => $prices->currentPage(), 'last_page' => $prices->lastPage(), 'per_page' => $prices->perPage(),
                'total' => $prices->total(), 'from' => $prices->firstItem(), 'to' => $prices->lastItem(),
                'next_page_url' => $prices->nextPageUrl(), 'prev_page_url' => $prices->previousPageUrl()],
            'message' => 'Seed prices retrieved successfully.',
        ]);
    }

    public function store(StoreSeedPriceRequest $request): JsonResponse
    {
        try {
            $result = $this->persistPrice(new SeedPrice(['currency_code' => config('seed_prices.default_currency')]), $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to create the seed price. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new SeedPriceResource($result), 'pagination' => null, 'message' => 'Seed price created successfully.'], 201);
    }

    public function show(SeedPrice $seedPrice): JsonResponse
    {
        $seedPrice->load($this->displayRelations(true));

        return response()->json(['status' => true, 'data' => new SeedPriceResource($seedPrice), 'pagination' => null, 'message' => 'Seed price retrieved successfully.']);
    }

    public function update(UpdateSeedPriceRequest $request, SeedPrice $seedPrice): JsonResponse
    {
        try {
            $result = $this->persistPrice($seedPrice, $request->validated());
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the seed price. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new SeedPriceResource($result), 'pagination' => null, 'message' => 'Seed price updated successfully.']);
    }

    public function updateStatus(UpdateSeedPriceStatusRequest $request, SeedPrice $seedPrice): JsonResponse
    {
        try {
            $result = $this->persistPrice($seedPrice, $request->validated(), statusOnly: true);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to update the seed price status. Please try again later.');
        }
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['status' => true, 'data' => new SeedPriceResource($result), 'pagination' => null, 'message' => 'Seed price status updated successfully.']);
    }

    /** @param array<string, mixed> $input */
    private function persistPrice(SeedPrice $existing, array $input, bool $statusOnly = false): SeedPrice|JsonResponse
    {
        $id = $existing->exists ? $existing->id : null;

        return DB::transaction(function () use ($existing, $id, $input, $statusOnly): SeedPrice|JsonResponse {
            $price = $id === null ? clone $existing : SeedPrice::query()->lockForUpdate()->findOrFail($id);
            $wasActive = $price->is_active;
            if ($wasActive && ! $statusOnly) {
                Gate::authorize('review-knowledge-sources');
            }

            /** Serialize every writer for a seed, including first inserts; acquire parent locks before any snapshot reads. */
            $seedIds = array_values(array_unique(array_map('intval', array_filter([$price->seed_id, $input['seed_id'] ?? null]))));
            Seed::withTrashed()->whereIn('id', $seedIds)->orderBy('id')->lockForUpdate()->get(['id']);

            $price->loadMissing('regions');
            $before = array_map('intval', $price->regions->modelKeys());
            $regionIds = $price->resolveRegionIds($input);
            $price->fill(Arr::only($input, $price->getFillable()));
            if (! $statusOnly || $price->is_active) {
                $errors = $price->validationErrors($regionIds, lock: true);
                if ($errors !== []) {
                    throw ValidationException::withMessages($errors);
                }
            }
            if ($price->is_active) {
                Gate::authorize('review-knowledge-sources');
                $conflict = $price->overlappingActivePrice($regionIds);
                if ($conflict !== null) {
                    return $this->overlapConflict($conflict->id);
                }
            }
            $after = $regionIds;
            sort($before);
            sort($after);
            $changed = $price->isDirty(array_diff($price->getFillable(), ['is_active'])) || $before !== $after;
            if ($price->is_active && ($changed || ! $wasActive || $price->last_verified_at === null)) {
                $price->last_verified_at = now('UTC');
            } elseif ($changed) {
                $price->last_verified_at = null;
            }
            $syncRegions = ! $price->exists || array_key_exists('region_ids', $input) || array_key_exists('region_scope', $input);
            $price->save();
            if ($syncRegions) {
                $price->regions()->sync($regionIds);
            }

            return $price->load($this->displayRelations(true));
        }, attempts: 3);
    }

    public function destroy(SeedPrice $seedPrice): JsonResponse
    {
        try {
            DB::transaction(function () use ($seedPrice): void {
                $price = SeedPrice::query()->lockForUpdate()->findOrFail($seedPrice->id);
                abort_if($price->is_active || $price->hasExternalReferences(), 409, 'Active or referenced seed prices cannot be deleted. Deactivate the price instead.');
                $price->delete();
            }, attempts: 3);
        } catch (QueryException|PDOException $exception) {
            return $this->databaseFailure($exception, 'Unable to delete the seed price. Please try again later.');
        }

        return response()->json(['status' => true, 'data' => null, 'pagination' => null, 'message' => 'Seed price deleted successfully.']);
    }

    /** @return array<int|string, mixed> */
    private function displayRelations(bool $includeRegions = false): array
    {
        $locales = array_unique([app()->getLocale(), config('languages.default')]);
        $names = static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)
            ->select(['id', $translations->getForeignKeyName(), 'locale', 'name']);
        $relations = ['seed:id,crop_id,crop_variety_id,sku,material_type,default_unit_code,reference_pack_quantity,reference_pack_unit_code,is_active,setup_status',
            'seed.translations' => $names, 'seed.crop:id', 'seed.crop.translations' => $names,
            'seed.cropVariety:id', 'seed.cropVariety.translations' => $names, 'seed.primaryImage',
            'knowledgeSource:id,title,publisher,source_type,source_url,reference_identifier',
            'knowledgeSource.translations' => static fn (HasMany $translations): HasMany => $translations->whereIn('locale', $locales)->select(['id', 'knowledge_source_id', 'locale', 'title'])];
        if ($includeRegions) {
            $relations['regions'] = static fn (BelongsToMany $regions): BelongsToMany => $regions->orderBy('regions.id');
            $relations['regions.translations'] = $names;
        }

        return $relations;
    }

    private function overlapConflict(int $id): JsonResponse
    {
        return response()->json(['status' => false, 'data' => null, 'pagination' => null,
            'message' => 'Active seed price '.$id.' overlaps this seller, package, price type, currency, effective period and district coverage. Revise or deactivate the conflicting price.'], 409);
    }
}

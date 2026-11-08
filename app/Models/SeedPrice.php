<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\SeedPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SeedPrice extends Model
{
    /** @use HasFactory<SeedPriceFactory> */
    use HasFactory;

    public const PRICE_TYPES = ['mrp', 'retail', 'wholesale', 'indicative'];

    public const REGION_SCOPES = CropStageTimeline::REGION_SCOPES;

    protected $fillable = ['seed_id', 'seller_name', 'price_type', 'package_quantity', 'package_unit_code', 'currency_code',
        'package_price', 'region_scope', 'effective_from', 'effective_to', 'source_id', 'is_active'];

    protected $attributes = ['is_active' => false, 'currency_code' => 'INR'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['seed_id' => 'integer', 'source_id' => 'integer', 'package_quantity' => 'decimal:4', 'package_price' => 'decimal:4',
            'effective_from' => 'immutable_date:Y-m-d', 'effective_to' => 'immutable_date:Y-m-d',
            'last_verified_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }

    public function seed(): BelongsTo
    {
        return $this->belongsTo(Seed::class)->withTrashed();
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function regions(): BelongsToMany
    {
        return $this->belongsToMany(Region::class, 'seed_price_regions');
    }

    /**
     * Omitted districts survive PATCH; switching to all explicitly clears mappings.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    public function resolveRegionIds(array $input): array
    {
        if (array_key_exists('region_ids', $input)) {
            return array_values(array_map('intval', $input['region_ids']));
        }
        if (($input['region_scope'] ?? null) === 'all' || ! $this->exists) {
            return [];
        }
        if (($input['region_scope'] ?? null) === 'specific' && $this->region_scope !== 'specific') {
            return [];
        }
        $this->loadMissing('regions');

        return array_map('intval', $this->regions->modelKeys());
    }

    /**
     * Retained inactive districts stay valid; disabled districts cannot be newly added.
     *
     * @param  list<int>  $regionIds
     * @return array<string, list<string>>
     */
    public function validationErrors(array $regionIds, bool $lock = false): array
    {
        $errors = [];
        if ($this->package_quantity === null || BigDecimal::of($this->package_quantity)->isLessThanOrEqualTo(0)) {
            $errors['package_quantity'] = ['The package quantity must be greater than zero.'];
        }
        if ($this->package_price === null || BigDecimal::of($this->package_price)->isNegative()) {
            $errors['package_price'] = ['The package price must be non-negative.'];
        }
        if (! in_array($this->price_type, self::PRICE_TYPES, true)) {
            $errors['price_type'] = ['Select a valid price type.'];
        }
        if (! in_array($this->currency_code, config('seed_prices.currencies'), true)) {
            $errors['currency_code'] = ['Select a supported currency.'];
        }
        $seed = Seed::withTrashed()->whereKey($this->seed_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
        if ($seed === null || ((! $this->exists || $this->isDirty('seed_id') || $this->is_active)
            && ! Seed::query()->published()->whereKey($this->seed_id)->exists())) {
            $errors['seed_id'] = ['Select an available completed and published seed product.'];
        }
        if ($seed !== null && ! in_array($this->package_unit_code, $seed->compatibleUnits(), true)) {
            $errors['package_unit_code'] = ['The package unit is incompatible with the selected seed material.'];
        }
        if ($this->effective_from !== null && $this->effective_to !== null && $this->effective_to->lt($this->effective_from)) {
            $errors['effective_to'] = ['The effective end date must be on or after the start date.'];
        }
        if (! in_array($this->region_scope, self::REGION_SCOPES, true)) {
            $errors['region_scope'] = ['The region scope must be all or specific.'];
        } elseif ($this->region_scope === 'all' && $regionIds !== []) {
            $errors['region_ids'] = ['All-regions prices cannot contain district selections.'];
        } elseif ($this->region_scope === 'specific' && $regionIds === []) {
            $errors['region_ids'] = ['Select at least one district.'];
        }
        $this->loadMissing('regions');
        $savedIds = array_map('intval', $this->regions->modelKeys());
        $regions = Region::query()->whereIn('id', $regionIds)->orderBy('id')->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get(['id', 'region_type', 'is_active'])->keyBy('id');
        foreach ($regionIds as $index => $id) {
            $region = $regions->get($id);
            if ($region === null || $region->region_type !== 'district') {
                $errors['region_ids.'.$index] = ['Select an existing district.'];
            } elseif (! $region->is_active && ! in_array($id, $savedIds, true)) {
                $errors['region_ids.'.$index] = ['Inactive districts cannot be newly selected.'];
            }
        }
        if ($this->source_id !== null) {
            $source = KnowledgeSource::query()->whereKey($this->source_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->first();
            if ($source === null || ((! $this->exists || $this->isDirty('source_id') || $this->is_active)
                && (! $source->is_active || $source->verification_status !== 'verified' || ! $source->hasUsableReference()))) {
                $errors['source_id'] = ['Select an active, verified knowledge source with a usable reference.'];
            }
        }
        $before = $savedIds;
        $after = $regionIds;
        sort($before);
        sort($after);
        if ($this->exists && ($this->isDirty(array_diff($this->getFillable(), ['is_active'])) || $before !== $after) && $this->hasExternalReferences()) {
            $errors['seed_id'] = ['Referenced historical prices cannot be rewritten. Deactivate this record and create a new price period.'];
        }

        return $errors;
    }

    /**
     * No fallback pricing policy exists: all-region and intersecting district prices compete.
     * Compare physical package sizes exactly (g/kg for mass; piece/plant for count).
     * Call after acquiring the parent Seed row lock, including when no matching price exists yet.
     *
     * @param  list<int>  $regionIds
     */
    public function overlappingActivePrice(array $regionIds): ?self
    {
        $quantity = BigDecimal::of($this->package_quantity);
        $query = self::query()->where('is_active', true)->where('seed_id', $this->seed_id)->where('price_type', $this->price_type)
            ->where('currency_code', $this->currency_code)
            ->whereRaw("LOWER(TRIM(COALESCE(seller_name, ''))) = LOWER(?)", [$this->seller_name ?? ''])
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->id))
            ->where(function (Builder $query) use ($quantity): void {
                if (in_array($this->package_unit_code, ['g', 'kg'], true)) {
                    $grams = $this->package_unit_code === 'kg' ? $quantity->multipliedBy(1000) : $quantity;
                    $query->where(fn (Builder $query): Builder => $query->where('package_unit_code', 'g')->where('package_quantity', (string) $grams))
                        ->orWhere(fn (Builder $query): Builder => $query->where('package_unit_code', 'kg')->where('package_quantity', (string) $grams->withPointMovedLeft(3)));
                } else {
                    $query->whereIn('package_unit_code', ['piece', 'plant'])->where('package_quantity', (string) $quantity);
                }
            });
        if ($this->effective_to !== null) {
            $query->where('effective_from', '<=', $this->effective_to->toDateString());
        }
        $query->where(fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $this->effective_from->toDateString()));
        if ($this->region_scope === 'specific') {
            $query->where(fn (Builder $query): Builder => $query->where('region_scope', 'all')
                ->orWhereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds)));
        }

        return $query->orderBy('id')->first(['id']);
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'seed_price_regions') {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'seed_prices' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

<?php

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    /** @var array<string, string|null> */
    public const PARENT_TYPES = [
        'country' => null,
        'state' => 'country',
        'district' => 'state',
        'subdistrict' => 'district',
        'village' => 'subdistrict',
    ];

    /**
     * Shared applicability for the five agricultural regional catalogues. Each EXISTS keeps one row per master.
     * NULL optional conditions are generic; an unknown farm condition never matches a specific condition.
     *
     * @param  list<int>  $regionIds
     * @param  array<string, mixed>  $conditions
     */
    public static function applyAgriculturalContext(Builder $query, array $regionIds, array $conditions = [], bool $sourceRequired = true): Builder
    {
        $table = $query->getModel()->getTable();
        $query->where($table.'.is_active', true)->where(function (Builder $scope) use ($regionIds, $table): void {
            $scope->where($table.'.region_scope', 'all')->orWhere(function (Builder $specific) use ($regionIds, $table): void {
                $specific->where($table.'.region_scope', 'specific')
                    ->whereHas('regions', fn (Builder $regions): Builder => $regions->whereIn('regions.id', $regionIds)->where('regions.is_active', true));
            });
        });
        $source = fn (Builder $source): Builder => $source->where('is_active', true)->where('verification_status', 'verified')
            ->where(fn (Builder $reference): Builder => $reference->whereNotNull('source_url')->orWhereNotNull('reference_identifier')->orWhereHas('attachments'));
        if ($sourceRequired) {
            $query->whereHas('knowledgeSource', $source);
        } else {
            $query->where(fn (Builder $query): Builder => $query->whereNull($table.'.source_id')->orWhereHas('knowledgeSource', $source));
        }
        foreach ($conditions as $field => $value) {
            if ($field === 'crop_id') {
                $query->where($table.'.'.$field, $value);
            } else {
                $query->where(fn (Builder $query): Builder => $value === null ? $query->whereNull($table.'.'.$field)
                    : $query->whereNull($table.'.'.$field)->orWhere($table.'.'.$field, $value));
            }
        }
        foreach ($regionIds as $rank => $regionId) {
            $alias = 'farm_region_match_'.$rank;
            $query->withExists(['regions as '.$alias => fn (Builder $regions): Builder => $regions->where('regions.id', $regionId)
                ->where($table.'.region_scope', 'specific')])->orderByDesc($alias);
        }
        foreach (array_diff(array_keys($conditions), ['crop_id']) as $field) {
            $query->orderByDesc($table.'.'.$field);
        }

        return $query->with(['regions' => fn (BelongsToMany $regions): BelongsToMany => $regions->withLocalizedName(), 'knowledgeSource.translations']);
    }

    /** @param list<int> $regionIds */
    public static function applyPlanningPrices(Builder $query, array $regionIds, string $date, string $currency): Builder
    {
        return self::applyAgriculturalContext($query, $regionIds, sourceRequired: false)
            ->where('currency_code', $currency)->where('package_quantity', '>', 0)->where('package_price', '>=', 0)
            ->where('effective_from', '<=', $date)
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    /** @param list<int> $regionIds
     * @return array<string, mixed>
     */
    public static function agriculturalMatch(Model $record, array $regionIds): array
    {
        foreach ($regionIds as $rank => $id) {
            if ($record->region_scope === 'specific' && ($region = $record->regions->firstWhere('id', $id)) !== null) {
                return ['matched_region' => ['id' => $id, 'name' => $region->localized_name, 'region_type' => $region->region_type],
                    'match_type' => $rank === 0 ? 'exact_region' : 'parent_region', 'region_specificity' => $rank];
            }
        }

        return ['matched_region' => null, 'match_type' => 'general', 'region_specificity' => null];
    }

    /** @var list<string> */
    protected $fillable = ['parent_id', 'code', 'region_type', 'latitude', 'longitude', 'is_active'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'is_active' => 'boolean', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(RegionTranslation::class);
    }

    public function cropStageTimelines(): BelongsToMany
    {
        return $this->belongsToMany(CropStageTimeline::class, 'crop_stage_timeline_regions', 'region_id', 'crop_stage_timeline_id');
    }

    public function cropActivityTemplates(): BelongsToMany
    {
        return $this->belongsToMany(CropActivityTemplate::class, 'crop_activity_template_regions', 'region_id', 'crop_activity_template_id');
    }

    public function seedPrices(): BelongsToMany
    {
        return $this->belongsToMany(SeedPrice::class, 'seed_price_regions');
    }

    public function fertilizerPrices(): BelongsToMany
    {
        return $this->belongsToMany(FertilizerPrice::class, 'fertilizer_price_regions');
    }

    public function cropSeedRates(): BelongsToMany
    {
        return $this->belongsToMany(CropSeedRate::class, 'crop_seed_rate_regions');
    }

    public function yieldBenchmarks(): BelongsToMany
    {
        return $this->belongsToMany(CropYieldBenchmark::class, 'crop_yield_benchmark_regions');
    }

    public function fertilizerRecommendations(): BelongsToMany
    {
        return $this->belongsToMany(FertilizerRecommendation::class, 'fertilizer_recommendation_regions');
    }

    public function translatedName(?string $locale = null): ?string
    {
        $this->loadMissing('translations');

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())?->name
            ?? $this->translations->firstWhere('locale', config('languages.default'))?->name;
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('region_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.region_id', '=', 'regions.id')->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('region_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.region_id', '=', 'regions.id')->where('default_translation.locale', config('languages.default'));
        })->select('regions.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /** @return array<string, list<string>> */
    public function hierarchyErrors(bool $lock = false): array
    {
        $requiredParentType = self::PARENT_TYPES[$this->region_type];
        if ($requiredParentType === null && $this->parent_id !== null) {
            return ['parent_id' => ['A country cannot have a parent region.']];
        }
        if ($requiredParentType !== null && $this->parent_id === null) {
            return ['parent_id' => ['A '.$this->region_type.' must have a '.$requiredParentType.' parent.']];
        }

        $ancestorId = $this->parent_id;
        $visited = $this->exists ? [$this->id => true] : [];
        $isDirectParent = true;
        while ($ancestorId !== null) {
            if (isset($visited[$ancestorId])) {
                return ['parent_id' => ['A region cannot be its own ancestor or be moved under a descendant.']];
            }
            $visited[$ancestorId] = true;
            $ancestor = self::query()->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->find($ancestorId);
            if ($ancestor === null) {
                return ['parent_id' => ['The selected parent region does not exist.']];
            }
            if ($isDirectParent && $ancestor->region_type !== $requiredParentType) {
                return ['parent_id' => ['The parent region must be a '.$requiredParentType.'.']];
            }
            $isDirectParent = false;
            $ancestorId = $ancestor->parent_id;
        }

        if ($this->exists && $this->isDirty('region_type')
            && $this->children()->whereNotIn('region_type', array_keys(self::PARENT_TYPES, $this->region_type, true))->exists()) {
            return ['region_type' => ['The region type cannot change because it would invalidate existing children.']];
        }

        return [];
    }

    /**
     * Recheck mutable hierarchy state under locks after Form Request validation.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{name: string}>  $translations
     */
    public function saveWithTranslations(array $attributes, array $translations): self
    {
        $regionId = $this->exists ? $this->id : null;

        return DB::transaction(function () use ($regionId, $attributes, $translations): self {
            $region = $regionId !== null ? self::query()->lockForUpdate()->findOrFail($regionId) : new self;
            $region->fill($attributes);
            $errors = $region->hierarchyErrors(lock: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $region->save();
            foreach ($translations as $locale => $translation) {
                $region->translations()->updateOrCreate(['locale' => $locale], ['name' => $translation['name']]);
            }

            return $region->load(['translations', 'parent.translations']);
        }, attempts: 3);
    }
}

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

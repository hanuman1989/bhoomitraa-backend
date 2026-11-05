<?php

namespace App\Models;

use Database\Factories\CropCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class CropCategory extends Model
{
    /** @use HasFactory<CropCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['slug'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropCategoryTranslation::class);
    }

    public function crops(): HasMany
    {
        return $this->hasMany(Crop::class, 'category_id');
    }

    /** Resolve loaded translations only; resources must never trigger database queries. */
    public function translatedName(?string $locale = null): ?string
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())?->name
            ?? $this->translations->firstWhere('locale', config('languages.default'))?->name;
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('crop_category_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.crop_category_id', '=', 'crop_categories.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('crop_category_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.crop_category_id', '=', 'crop_categories.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('crop_categories.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /** Publication eligibility and locale loading for future farmer-facing consumers. */
    public function scopePublished(Builder $query): void
    {
        $locale = app()->getLocale();

        $query->where('crop_categories.is_active', true)
            ->whereHas('translations', fn (Builder $translations): Builder => $translations
                ->where('locale', config('languages.default'))->whereRaw("TRIM(name) <> ''"))
            ->withLocalizedName()
            ->with(['translations' => fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([$locale, config('languages.default')]))]);
    }

    /**
     * @param  array{slug?: string}  $attributes
     * @param  array<string, array{name: string, description?: string|null}>  $translations
     */
    public function saveWithTranslations(array $attributes, array $translations): self
    {
        $categoryId = $this->exists ? $this->id : null;

        return DB::transaction(function () use ($categoryId, $attributes, $translations): self {
            $category = $categoryId === null ? new self : self::query()->lockForUpdate()->findOrFail($categoryId);
            $category->fill($attributes);
            if (! $category->exists) {
                $category->is_active = false;
            }
            $category->save();

            foreach ($translations as $locale => $values) {
                $category->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $category->load('translations');
        }, attempts: 3);
    }
}

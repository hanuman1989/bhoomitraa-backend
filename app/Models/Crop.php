<?php

namespace App\Models;

use Database\Factories\CropFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Crop extends Model
{
    /** @use HasFactory<CropFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    /** @var list<string> */
    protected $fillable = ['category_id', 'slug', 'scientific_name', 'crop_code', 'is_active', 'ai_diagnosis_enabled'];

    /** @var list<string> */
    protected $hidden = ['image'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category_id' => 'integer', 'is_active' => 'boolean', 'ai_diagnosis_enabled' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CropCategory::class, 'category_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropTranslation::class);
    }

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(CropStage::class);
    }

    public function seeds(): HasMany
    {
        return $this->hasMany(Seed::class);
    }

    public function seedRates(): HasMany
    {
        return $this->hasMany(CropSeedRate::class);
    }

    public function yieldBenchmarks(): HasMany
    {
        return $this->hasMany(CropYieldBenchmark::class);
    }

    public function fertilizerRecommendations(): HasMany
    {
        return $this->hasMany(FertilizerRecommendation::class);
    }

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
        $query->leftJoin('crop_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.crop_id', '=', 'crops.id')->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('crop_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.crop_id', '=', 'crops.id')->where('default_translation.locale', config('languages.default'));
        })->select('crops.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /** Filter new catalogue selections without globally hiding historical crops. */
    public function scopePublished(Builder $query): void
    {
        $locale = app()->getLocale();
        $defaultLocale = config('languages.default');

        $query->where('crops.is_active', true)
            ->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true))
            ->withLocalizedName()
            ->with([
                'translations' => fn (HasMany $translations): HasMany => $translations->whereIn('locale', array_unique([$locale, $defaultLocale])),
                'category' => fn (BelongsTo $category): BelongsTo => $category->withLocalizedName(),
            ]);
        foreach (self::REQUIRED_LOCALES as $requiredLocale) {
            $query->whereHas('translations', fn (Builder $translations): Builder => $translations
                ->where('locale', $requiredLocale)->whereRaw("TRIM(name) <> ''"));
        }
    }

    /**
     * Rechecked while holding the category lock during persistence.
     *
     * @param  array<string, array{name: string, description?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function activationErrors(array $translations = [], bool $lockCategory = false): array
    {
        $category = CropCategory::query()->when($lockCategory, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->category_id);
        if ($category === null) {
            return ['category_id' => ['The selected crop category does not exist.']];
        }
        if (! $this->is_active) {
            return [];
        }

        $errors = $category->is_active ? [] : ['category_id' => ['An active crop requires an active category.']];
        $names = $this->exists ? $this->translations()->whereIn('locale', self::REQUIRED_LOCALES)->pluck('name', 'locale')->all() : [];
        foreach ($translations as $locale => $values) {
            $names[$locale] = $values['name'];
        }
        foreach (self::REQUIRED_LOCALES as $locale) {
            if (blank($names[$locale] ?? null)) {
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' crop name is required before activation.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array{name: string, description?: string|null}>  $translations
     */
    public function saveWithTranslations(array $attributes, array $translations): self
    {
        $cropId = $this->exists ? $this->id : null;

        return DB::transaction(function () use ($cropId, $attributes, $translations): self {
            $crop = $cropId === null ? new self : self::query()->lockForUpdate()->findOrFail($cropId);
            if (! $crop->exists) {
                $crop->is_active = false;
                $crop->ai_diagnosis_enabled = false;
            }
            $crop->fill($attributes);
            $errors = $crop->activationErrors($translations, lockCategory: true);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $crop->save();
            foreach ($translations as $locale => $values) {
                $crop->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $crop->load(['translations', 'category.translations']);
        }, attempts: 3);
    }
}

<?php

namespace App\Models;

use Database\Factories\ActivityCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ActivityCategory extends Model
{
    /** @use HasFactory<ActivityCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    /**
     * Identifiers exported by the existing frontend's src/icons/index.tsx.
     *
     * @var list<string>
     */
    public const SUPPORTED_ICONS = [
        'AgricultureIcon', 'CropCategoryIcon', 'LeafIcon', 'SeedIcon', 'TaskIcon',
        'ListIcon', 'CalenderIcon', 'TimeIcon', 'KnowledgeSourcesIcon',
        'TractorIcon',
        'SproutIcon',
        'DropletsIcon',
        'FlaskConicalIcon',
        'ShieldCheckIcon',
        'ScanEyeIcon',
        'ChartNoAxesColumnIncreasingIcon',
        'BotIcon',
        'UserRoundCheckIcon',
        'HarvestingIcon',
        'ClipboardListIcon',

    ];

    /**
     * Reserved actions remain unavailable until their supporting features are released.
     *
     * @var list<string>
     */
    public const UNRELEASED_SLUGS = ['ask-ai-doctor', 'ask-agricultural-expert'];

    /** @var list<string> */
    protected $fillable = ['slug', 'icon', 'is_system', 'is_active'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ActivityCategoryTranslation::class);
    }

    public function translation(?string $locale = null): ?ActivityCategoryTranslation
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }

    public function translatedName(?string $locale = null): ?string
    {
        return $this->translation($locale)?->name;
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('activity_category_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.activity_category_id', '=', 'activity_categories.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('activity_category_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.activity_category_id', '=', 'activity_categories.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('activity_categories.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /** Limit new selections without hiding inactive categories from historical relationships. */
    public function scopePublished(Builder $query): void
    {
        $query->where('activity_categories.is_active', true)->whereNotIn('activity_categories.slug', self::UNRELEASED_SLUGS)
            ->withLocalizedName()->with(['translations' => fn (HasMany $translations): HasMany => $translations
            ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))]);
        foreach (self::REQUIRED_LOCALES as $locale) {
            $query->whereHas('translations', fn (Builder $translations): Builder => $translations
                ->where('locale', $locale)->whereRaw("TRIM(name) <> ''"));
        }
    }

    /**
     * @param  array<string, array{name?: string, description?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function translationErrors(array $translations = []): array
    {
        $this->loadMissing('translations');
        $names = $this->translations->pluck('name', 'locale')->all();
        foreach ($translations as $locale => $values) {
            if (array_key_exists('name', $values)) {
                $names[$locale] = $values['name'];
            }
        }
        $errors = [];
        foreach (array_unique([...self::REQUIRED_LOCALES, ...array_keys($translations)]) as $locale) {
            if (blank($names[$locale] ?? null)) {
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' activity category name is required.'];
            }
        }

        return $errors;
    }

    /** @return array<string, list<string>> */
    public function activationErrors(): array
    {
        $errors = $this->translationErrors();
        if (in_array($this->slug, self::UNRELEASED_SLUGS, true)) {
            $errors['is_active'] = ['This activity is unavailable until its supporting feature is released.'];
        }

        return $errors;
    }

    /**
     * @param  array{slug?: string, icon?: string|null, is_system?: bool}  $attributes
     * @param  array<string, array{name?: string, description?: string|null}>  $translations
     */
    public function saveWithTranslations(array $attributes, array $translations): self
    {
        $categoryId = $this->exists ? $this->id : null;

        return DB::transaction(function () use ($categoryId, $attributes, $translations): self {
            $category = $categoryId === null ? new self : self::query()->lockForUpdate()->findOrFail($categoryId);
            $category->fill($attributes);
            if ($category->exists && $category->isDirty('is_system')) {
                throw ValidationException::withMessages(['is_system' => ['The system category flag cannot be changed.']]);
            }
            if ($category->exists && $category->is_system && $category->isDirty('slug')) {
                throw ValidationException::withMessages(['slug' => ['A system category slug cannot be changed.']]);
            }
            if (! $category->exists) {
                $category->is_system = (bool) ($attributes['is_system'] ?? false);
                $category->is_active = false;
            }
            $errors = $category->translationErrors($translations);
            if ($category->is_active && in_array($category->slug, self::UNRELEASED_SLUGS, true)) {
                $errors['slug'] = ['An active category cannot use an unreleased activity slug.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            $category->save();
            foreach ($translations as $locale => $values) {
                $category->translations()->updateOrCreate(['locale' => $locale], $values);
            }

            return $category->load('translations');
        }, attempts: 3);
    }

    /** Inspect actual foreign keys, including cascading or nullable history references. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'activity_category_translations') {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== $this->getTable()) {
                    continue;
                }
                $position = array_search('id', $foreignKey['foreign_columns'] ?? [], true);
                if ($position !== false && DB::table($table['name'])->where($foreignKey['columns'][$position], $this->getKey())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

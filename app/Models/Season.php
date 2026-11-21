<?php

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    public const ICONS = ['sprout', 'sun', 'leaf'];

    public const PRESENTATION_FIELDS = ['start_month', 'end_month', 'icon'];

    /** @var list<string> */
    protected $fillable = ['slug', ...self::PRESENTATION_FIELDS];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'start_month' => 'integer', 'end_month' => 'integer'];
    }

    /** @return array<string, list<string>> */
    public function presentationErrors(): array
    {
        if (($this->start_month === null) !== ($this->end_month === null)) {
            return array_fill_keys(['start_month', 'end_month'], ['Both start month and end month must be provided together.']);
        }
        $errors = [];
        foreach (['start_month', 'end_month'] as $field) {
            if ($this->{$field} !== null && ($this->{$field} < 1 || $this->{$field} > 12)) {
                $errors[$field] = ['The '.str_replace('_', ' ', $field).' must be between 1 and 12.'];
            }
        }
        if ($this->icon !== null && ! in_array($this->icon, self::ICONS, true)) {
            $errors['icon'] = ['The selected season icon is invalid.'];
        }

        return $errors;
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SeasonTranslation::class);
    }

    public function cropActivityTemplates(): BelongsToMany
    {
        return $this->belongsToMany(
            CropActivityTemplate::class,
            'crop_activity_template_seasons',
            'season_id',
            'crop_activity_template_id',
        );
    }

    public function cropStageTimelines(): BelongsToMany
    {
        return $this->belongsToMany(
            CropStageTimeline::class,
            'crop_stage_timeline_seasons',
            'season_id',
            'crop_stage_timeline_id',
        );
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

    /** Resolve loaded translations without triggering queries during serialization. */
    public function translation(?string $locale = null): ?SeasonTranslation
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

    public function translatedDescription(?string $locale = null): ?string
    {
        return $this->translation($locale)?->description;
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('season_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.season_id', '=', 'seasons.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('season_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.season_id', '=', 'seasons.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('seasons.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name')
            ->selectRaw('CASE WHEN requested_translation.id IS NOT NULL THEN requested_translation.description ELSE default_translation.description END as localized_description');
    }

    /** Limit new selections without hiding inactive seasons from historical relationships. */
    public function scopePublished(Builder $query): void
    {
        $query->where('seasons.is_active', true)->withLocalizedName()
            ->with(['translations' => fn (HasMany $translations): HasMany => $translations
                ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')]))]);
    }

    /** Inspect actual foreign keys so even cascading references cannot erase farming history. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'season_translations') {
                continue;
            }

            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                $foreignColumns = $foreignKey['foreign_columns'] ?? [];
                $columns = $foreignKey['columns'] ?? [];

                if (($foreignKey['foreign_table'] ?? null) !== $this->getTable()
                    || $foreignColumns !== ['id']
                    || count($columns) !== 1) {
                    continue;
                }

                if (DB::table($table['name'])->where($columns[0], $this->getKey())->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

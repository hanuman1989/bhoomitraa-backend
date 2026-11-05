<?php

namespace App\Models;

use Database\Factories\CropVarietyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CropVariety extends Model
{
    /** @use HasFactory<CropVarietyFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    /** @var list<string> */
    protected $fillable = [
        'crop_id',
        'slug',
        'variety_code',
        'maturity_days_min',
        'maturity_days_max',
        'source_id',
        'is_active',
    ];

    /** @var array<string, bool> */
    protected $attributes = ['is_active' => false];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'crop_id' => 'integer',
            'maturity_days_min' => 'integer',
            'maturity_days_max' => 'integer',
            'source_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropVarietyTranslation::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
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
        $query->leftJoin('crop_variety_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.crop_variety_id', '=', 'crop_varieties.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('crop_variety_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.crop_variety_id', '=', 'crop_varieties.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('crop_varieties.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /**
     * Recheck publication requirements while the parent records are locked.
     *
     * @param  array<string, array{name?: string, description?: string|null, region_notes?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function publicationErrors(array $translations = [], bool $lockRelationships = false): array
    {
        $cropQuery = Crop::query()->whereKey($this->crop_id);
        if ($lockRelationships) {
            $cropQuery->lockForUpdate();
        }
        $crop = $cropQuery->first();

        if ($crop === null) {
            return ['crop_id' => ['The selected crop does not exist.']];
        }

        if (! $this->is_active) {
            return [];
        }

        $errors = $crop->is_active ? [] : ['crop_id' => ['An active variety requires an active crop.']];
        $names = $this->exists
            ? $this->translations()->whereIn('locale', self::REQUIRED_LOCALES)->pluck('name', 'locale')->all()
            : [];

        foreach ($translations as $locale => $values) {
            if (array_key_exists('name', $values)) {
                $names[$locale] = $values['name'];
            }
        }

        foreach (self::REQUIRED_LOCALES as $locale) {
            if (blank($names[$locale] ?? null)) {
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' variety name is required before activation.'];
            }
        }

        if ($this->maturity_days_min === null && $this->maturity_days_max === null) {
            return $errors;
        }

        $sourceQuery = KnowledgeSource::query()->whereKey($this->source_id);
        if ($lockRelationships) {
            $sourceQuery->lockForUpdate();
        }
        $source = $sourceQuery->first();

        if ($source === null || ! $source->is_active || $source->verification_status !== 'verified') {
            $errors['source_id'] = ['An active, verified knowledge source is required to publish maturity information.'];
        }

        return $errors;
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'crop_variety_translations') {
                continue;
            }

            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                $foreignColumns = is_array($foreignKey['foreign_columns'] ?? null)
                    ? $foreignKey['foreign_columns']
                    : explode(',', $foreignKey['foreign_columns'] ?? '');
                $columns = is_array($foreignKey['columns'] ?? null)
                    ? $foreignKey['columns']
                    : explode(',', $foreignKey['columns'] ?? '');

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

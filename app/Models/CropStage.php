<?php

namespace App\Models;

use Database\Factories\CropStageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CropStage extends Model
{
    /** @use HasFactory<CropStageFactory> */
    use HasFactory;

    /** @var list<string> */
    public const REQUIRED_LOCALES = ['en', 'hi'];

    /** @var list<string> */
    protected $fillable = ['crop_id', 'code', 'sequence', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['crop_id' => 'integer', 'sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropStageTranslation::class);
    }

    public function timelines(): HasMany
    {
        return $this->hasMany(CropStageTimeline::class);
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
        $query->leftJoin('crop_stage_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.crop_stage_id', '=', 'crop_stages.id')
                ->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('crop_stage_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.crop_stage_id', '=', 'crop_stages.id')
                ->where('default_translation.locale', config('languages.default'));
        })->select('crop_stages.*')
            ->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    /** Filter new selections without hiding inactive stages from historical relationships. */
    public function scopePublished(Builder $query): void
    {
        $query->where('crop_stages.is_active', true)
            ->whereHas('crop', fn (Builder $crop): Builder => $crop->where('is_active', true))
            ->withLocalizedName()
            ->with([
                'translations' => fn (HasMany $translations): HasMany => $translations
                    ->whereIn('locale', array_unique([app()->getLocale(), config('languages.default')])),
                'crop.translations' => fn (HasMany $translations): HasMany => $translations->where('locale', config('languages.default')),
            ]);

        foreach (self::REQUIRED_LOCALES as $locale) {
            $query->whereHas('translations', fn (Builder $translations): Builder => $translations
                ->where('locale', $locale)->whereRaw("TRIM(name) <> ''"));
        }
    }

    /**
     * Rechecked under the crop lock during persistence.
     *
     * @param  array<string, array{name?: string, description?: string|null}>  $translations
     * @return array<string, list<string>>
     */
    public function activationErrors(array $translations = [], bool $lockCrop = false): array
    {
        if (! $this->is_active) {
            return [];
        }

        $crop = Crop::query()->when($lockCrop, fn (Builder $query): Builder => $query->lockForUpdate())->find($this->crop_id);
        $errors = $crop !== null && $crop->is_active
            ? []
            : ['crop_id' => ['An active growth stage requires an active crop.']];

        if (! is_string($this->code) || mb_strlen($this->code) > 50 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $this->code) !== 1) {
            $errors['code'] = ['A valid lowercase growth stage code is required before activation.'];
        }
        if ($this->sequence < 1 || $this->sequence > 65535) {
            $errors['sequence'] = ['A sequence between 1 and 65535 is required before activation.'];
        }

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
                $errors['translations.'.$locale.'.name'] = ['A nonempty '.$locale.' stage name is required before activation.'];
            }
        }

        return $errors;
    }

    /** Inspect actual foreign keys, including cascading dependencies, before deleting. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if ($table['name'] === 'crop_stage_translations') {
                continue;
            }

            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                $columns = $foreignKey['columns'] ?? [];
                if (($foreignKey['foreign_table'] ?? null) !== $this->getTable()
                    || ($foreignKey['foreign_columns'] ?? []) !== ['id']
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

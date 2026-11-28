<?php

namespace App\Models;

use Database\Factories\SowingMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\JoinClause;

class SowingMethod extends Model
{
    /** @use HasFactory<SowingMethodFactory> */
    use HasFactory;

    public const ESTABLISHMENT_TYPES = ['direct_sowing', 'transplanting', 'vegetative_planting', 'other'];

    public const CROP_SCOPES = ['all', 'specific'];

    public const MACHINERY_REQUIREMENTS = ['required', 'optional', 'not_required', 'varies'];

    public const LABOUR_REQUIREMENTS = ['low', 'medium', 'high', 'varies'];

    public const COST_LEVELS = ['low', 'medium', 'high', 'varies'];

    public const MAX_IMAGES = Seed::MAX_IMAGES;

    public const TRANSLATED_FIELDS = ['title', 'short_description', 'description', 'advantages', 'disadvantages',
        'suitable_conditions', 'resource_requirements', 'economic_considerations', 'important_considerations'];

    public const REQUIRED_TRANSLATED_FIELDS = ['title', 'short_description', 'description'];

    /** TEXT must also accommodate four-byte Unicode characters. */
    public const MAX_TEXT_LENGTH = 16000;

    protected $fillable = ['code', 'slug', 'establishment_type', 'crop_scope', 'supports_row_spacing',
        'supports_plant_spacing', 'supports_field_layout', 'machinery_requirement', 'labour_requirement',
        'cost_level', 'sort_order', 'is_active'];

    protected $attributes = ['crop_scope' => 'specific', 'supports_row_spacing' => null, 'supports_plant_spacing' => null,
        'supports_field_layout' => null, 'machinery_requirement' => 'varies', 'labour_requirement' => 'varies',
        'cost_level' => 'varies', 'sort_order' => 0, 'is_active' => false];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['supports_row_spacing' => 'boolean', 'supports_plant_spacing' => 'boolean',
            'supports_field_layout' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SowingMethodTranslation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SowingMethodImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverImage(): HasOne
    {
        return $this->hasOne(SowingMethodImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    public function crops(): BelongsToMany
    {
        return $this->belongsToMany(Crop::class, 'sowing_method_crops');
    }

    /** @return list<string> */
    public static function requiredLocales(): array
    {
        return array_keys(config('languages.supported'));
    }

    public function translation(?string $locale = null): ?SowingMethodTranslation
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }

    public function scopeWithLocalizedTitle(Builder $query): void
    {
        $query->leftJoin('sowing_method_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.sowing_method_id', '=', 'sowing_methods.id')->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('sowing_method_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.sowing_method_id', '=', 'sowing_methods.id')->where('default_translation.locale', config('languages.default'));
        })->select('sowing_methods.*')->selectRaw('COALESCE(requested_translation.title, default_translation.title) as localized_title');
    }

    public function scopeApplicableToCrop(Builder $query, int $cropId): void
    {
        $query->where(fn (Builder $query): Builder => $query->where('sowing_methods.crop_scope', 'all')
            ->orWhere(fn (Builder $specific): Builder => $specific->where('sowing_methods.crop_scope', 'specific')
                ->whereHas('crops', fn (Builder $crop): Builder => $crop->whereKey($cropId))));
    }

    /** Only configured methods are eligible for future farmer catalogue queries. */
    public function scopePublished(Builder $query): void
    {
        $query->where('sowing_methods.is_active', true)
            ->whereIn('sowing_methods.establishment_type', self::ESTABLISHMENT_TYPES)
            ->whereIn('sowing_methods.machinery_requirement', self::MACHINERY_REQUIREMENTS)
            ->whereIn('sowing_methods.labour_requirement', self::LABOUR_REQUIREMENTS)
            ->whereIn('sowing_methods.cost_level', self::COST_LEVELS)
            ->where(fn (Builder $scope): Builder => $scope
                ->where(fn (Builder $all): Builder => $all->where('crop_scope', 'all')->whereDoesntHave('crops'))
                ->orWhere(fn (Builder $specific): Builder => $specific->where('crop_scope', 'specific')->whereHas('crops')
                    ->whereDoesntHave('crops', fn (Builder $crop): Builder => $crop->whereNotIn('crops.id', Crop::query()->published()->select('crops.id')))));
        foreach (self::requiredLocales() as $locale) {
            $query->whereHas('translations', function (Builder $translation) use ($locale): void {
                $translation->where('locale', $locale);
                foreach (self::REQUIRED_TRANSLATED_FIELDS as $field) {
                    $translation->whereNotNull($field)->whereRaw('TRIM('.$field.") <> ''");
                }
            });
        }
    }
}

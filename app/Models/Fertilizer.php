<?php

namespace App\Models;

use Brick\Math\BigDecimal;
use Database\Factories\FertilizerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Fertilizer extends Model
{
    /** @use HasFactory<FertilizerFactory> */
    use HasFactory;

    public const TYPES = ['straight', 'complex', 'mixed', 'water_soluble', 'organic', 'biofertilizer', 'micronutrient', 'soil_amendment', 'other'];

    public const FORMS = ['granular', 'prilled', 'powder', 'crystalline', 'liquid', 'suspension', 'other'];

    public const APPLICATION_METHODS = ['soil_application', 'foliar_spray', 'fertigation', 'drip_application', 'other'];

    public const UNIT_CODES = ['g', 'kg', 'l', 'ml'];

    public const OPTIONAL_COMPOSITION_TYPES = ['organic', 'biofertilizer', 'soil_amendment', 'other'];

    public const MAX_IMAGES = Seed::MAX_IMAGES;

    protected $fillable = ['slug', 'fertilizer_code', 'fertilizer_type', 'form', 'brand_name', 'manufacturer_name', 'grade',
        'default_unit_code', 'reference_pack_quantity', 'reference_pack_unit_code', 'application_method'];

    protected $attributes = ['setup_status' => 'draft', 'completed_step' => 1, 'verification_status' => 'pending', 'is_active' => false, 'requested_is_active' => false];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'completed_step' => 'integer', 'is_active' => 'boolean', 'requested_is_active' => 'boolean',
            'reference_pack_quantity' => 'decimal:4'];
    }

    public function nutrients(): HasMany
    {
        return $this->hasMany(FertilizerNutrient::class)->orderBy('id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FertilizerTranslation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(FertilizerImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(FertilizerImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    /** @return list<string> */
    public static function requiredLocales(): array
    {
        return array_keys(config('languages.supported'));
    }

    public function translation(): ?FertilizerTranslation
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('fertilizer_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.fertilizer_id', '=', 'fertilizers.id')->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('fertilizer_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.fertilizer_id', '=', 'fertilizers.id')->where('default_translation.locale', config('languages.default'));
        })->select('fertilizers.*')->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('fertilizers.setup_status', 'completed')->where('fertilizers.completed_step', 5)
            ->where('fertilizers.is_active', true)->where('fertilizers.verification_status', 'verified')
            ->whereIn('fertilizers.fertilizer_type', self::TYPES)->whereIn('fertilizers.form', self::FORMS)->whereIn('fertilizers.default_unit_code', self::UNIT_CODES)
            ->where(fn (Builder $query): Builder => $query->whereIn('fertilizer_type', self::OPTIONAL_COMPOSITION_TYPES)->orWhereHas('nutrients'))
            ->where(fn (Builder $query): Builder => $query->whereNull('source_id')->orWhereHas('knowledgeSource',
                fn (Builder $source): Builder => $source->where('is_active', true)->where('verification_status', 'verified')));
        foreach (self::requiredLocales() as $locale) {
            $query->whereHas('translations', fn (Builder $translation): Builder => $translation->where('locale', $locale)->whereRaw("TRIM(name) <> ''"));
        }
    }

    /** @return array<string, list<string>> */
    public function basicErrors(): array
    {
        $errors = [];
        if (($this->reference_pack_quantity === null) !== ($this->reference_pack_unit_code === null)) {
            $errors['reference_pack_quantity'] = ['Provide both a positive pack quantity and a compatible pack unit, or clear both.'];
        }
        if ($this->reference_pack_quantity !== null && BigDecimal::of($this->reference_pack_quantity)->isLessThanOrEqualTo(0)) {
            $errors['reference_pack_quantity'] = ['The reference pack quantity must be greater than zero.'];
        }
        if ($this->reference_pack_unit_code !== null && (
            in_array($this->default_unit_code, ['g', 'kg'], true) !== in_array($this->reference_pack_unit_code, ['g', 'kg'], true))) {
            $errors['reference_pack_unit_code'] = ['The pack unit must have the same quantity dimension as the default unit.'];
        }

        return $errors;
    }

    /** Reopen meaningful content edits as drafts while retaining every saved step. */
    public function invalidateVerification(): void
    {
        $this->verification_status = 'pending';
        $this->is_active = false;
        $this->setup_status = 'draft';
        $this->completed_step = min($this->completed_step, 4);
    }

    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], ['fertilizer_translations', 'fertilizer_images', 'fertilizer_nutrients'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'fertilizer_nutrients' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->whereIn($foreignKey['columns'][0], $this->nutrients()->select('id'))->exists()) {
                    return true;
                }
                if (($foreignKey['foreign_table'] ?? null) === 'fertilizers' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

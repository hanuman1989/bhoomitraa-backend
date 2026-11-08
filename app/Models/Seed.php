<?php

namespace App\Models;

use Database\Factories\SeedFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Seed extends Model
{
    /** @use HasFactory<SeedFactory> */
    use HasFactory;

    use SoftDeletes;

    public const MATERIAL_TYPES = ['seed', 'seedling', 'grafted_plant', 'rooted_cutting', 'tissue_culture_plant', 'tuber', 'bulb', 'clove', 'rhizome', 'sett', 'sucker', 'runner', 'corm', 'vine_cutting', 'other'];

    public const SEED_TYPES = ['improved', 'hybrid', 'open_pollinated', 'local', 'other'];

    public const PROPAGATION_METHODS = ['seed', 'cutting', 'grafting', 'budding', 'tissue_culture', 'division', 'vegetative', 'other'];

    public const SEED_CLASSES = ['breeder', 'foundation', 'certified', 'truthfully_labelled', 'other'];

    public const TREATMENT_STATUSES = ['treated', 'untreated', 'unknown'];

    public const UNIT_CODES = ['g', 'kg', 'piece', 'plant'];

    public const SEED_ONLY_FIELDS = ['seed_type', 'seed_class', 'seeds_per_gram', 'germination_reference_pct', 'is_f1_hybrid', 'is_pelleted'];

    public const MAX_IMAGES = 8;

    protected $fillable = ['crop_id', 'crop_variety_id', 'slug', 'sku', 'producer_name', 'brand_name', 'seed_type', 'material_type',
        'propagation_method', 'seed_class', 'treatment_status', 'is_f1_hybrid', 'is_organic', 'is_pelleted', 'default_unit_code',
        'reference_pack_quantity', 'reference_pack_unit_code', 'seeds_per_gram', 'germination_reference_pct', 'purity_reference_pct'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = ['setup_status' => 'draft', 'completed_step' => 1, 'verification_status' => 'pending', 'is_active' => false, 'requested_is_active' => false];

    protected function casts(): array
    {
        return ['crop_id' => 'integer', 'crop_variety_id' => 'integer', 'source_id' => 'integer', 'completed_step' => 'integer',
            'is_active' => 'boolean', 'requested_is_active' => 'boolean', 'is_f1_hybrid' => 'boolean', 'is_organic' => 'boolean', 'is_pelleted' => 'boolean',
            'reference_pack_quantity' => 'decimal:4', 'seeds_per_gram' => 'decimal:3', 'germination_reference_pct' => 'decimal:2', 'purity_reference_pct' => 'decimal:2'];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }

    public function knowledgeSource(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'source_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(SeedTranslation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(SeedImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(SeedLot::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(SeedPrice::class);
    }

    public function seedRates(): HasMany
    {
        return $this->hasMany(CropSeedRate::class);
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(SeedImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    /** @return list<string> */
    public static function requiredLocales(): array
    {
        return array_keys(config('languages.supported'));
    }

    public function translation(): ?SeedTranslation
    {
        return $this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }

    public function scopeWithLocalizedName(Builder $query): void
    {
        $query->leftJoin('seed_translations as requested_translation', function (JoinClause $join): void {
            $join->on('requested_translation.seed_id', '=', 'seeds.id')->where('requested_translation.locale', app()->getLocale());
        })->leftJoin('seed_translations as default_translation', function (JoinClause $join): void {
            $join->on('default_translation.seed_id', '=', 'seeds.id')->where('default_translation.locale', config('languages.default'));
        })->select('seeds.*')->selectRaw('COALESCE(requested_translation.name, default_translation.name) as localized_name');
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('seeds.setup_status', 'completed')->where('seeds.completed_step', 4)
            ->where('seeds.is_active', true)->where('seeds.verification_status', 'verified')
            ->whereHas('crop', fn (Builder $crop): Builder => $crop->where('is_active', true)->whereHas('category', fn (Builder $category): Builder => $category->where('is_active', true)))
            ->where(fn (Builder $query): Builder => $query->whereNull('crop_variety_id')->orWhereHas('cropVariety', fn (Builder $variety): Builder => $variety->where('is_active', true)->whereColumn('crop_varieties.crop_id', 'seeds.crop_id')))
            ->whereHas('knowledgeSource', fn (Builder $source): Builder => $source->where('is_active', true)->where('verification_status', 'verified'));
        foreach (self::requiredLocales() as $locale) {
            $query->whereHas('translations', fn (Builder $translation): Builder => $translation->where('locale', $locale)->whereRaw("TRIM(name) <> ''"));
        }
    }

    /** @return list<string> */
    public function compatibleUnits(): array
    {
        return in_array($this->material_type, ['seed', 'tuber', 'bulb', 'clove', 'rhizome', 'sett', 'corm', 'other'], true)
            ? ['g', 'kg', 'piece'] : ['piece', 'plant'];
    }

    /** @return array<string, list<string>> */
    public function basicErrors(bool $lock = false): array
    {
        $errors = [];
        if (! Crop::query()->published()->whereKey($this->crop_id)->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->exists()) {
            $errors['crop_id'] = ['Select an eligible active crop with its required translations.'];
        }
        if ($this->crop_variety_id !== null && ! CropVariety::query()->whereKey($this->crop_variety_id)->where('crop_id', $this->crop_id)->where('is_active', true)
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())->exists()) {
            $errors['crop_variety_id'] = ['Select an active variety belonging to the selected crop.'];
        }
        foreach (['default_unit_code', 'reference_pack_unit_code'] as $field) {
            if ($this->{$field} !== null && ! in_array($this->{$field}, $this->compatibleUnits(), true)) {
                $errors[$field] = ['The unit is incompatible with this planting material.'];
            }
        }
        if (($this->reference_pack_quantity === null) !== ($this->reference_pack_unit_code === null)) {
            $errors['reference_pack_quantity'] = ['Provide both a positive pack quantity and its compatible unit, or clear both.'];
        }

        return $errors;
    }

    public function invalidateVerification(): void
    {
        $this->verification_status = 'pending';
        $this->is_active = false;
    }

    /** Inspect real dependencies, including cascades and soft-deleted history. */
    public function hasExternalReferences(): bool
    {
        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], ['seed_translations', 'seed_images'], true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table['name']) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) === 'seeds' && ($foreignKey['foreign_columns'] ?? []) === ['id']
                    && count($foreignKey['columns'] ?? []) === 1 && DB::table($table['name'])->where($foreignKey['columns'][0], $this->id)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }
}

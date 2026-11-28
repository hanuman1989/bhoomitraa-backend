<?php

namespace App\Models;

use Database\Factories\CropFieldLayoutFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropFieldLayout extends Model
{
    /** @use HasFactory<CropFieldLayoutFactory> */
    use HasFactory;

    public const BASIC_FIELDS = ['crop_scope', 'crop_variety_id', 'sowing_method_id', 'farming_type', 'growing_system', 'layout_type', 'sort_order'];

    public const TRANSLATED_FIELDS = ['title', 'description', 'important_considerations'];

    protected $fillable = ['layout_type', 'crop_scope', 'crop_variety_id', 'sowing_method_id', 'farming_type',
        'growing_system', 'configuration_version', 'configuration', 'reference_note', 'sort_order', 'is_active'];

    protected $hidden = ['wizard_draft'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['configuration' => 'array', 'wizard_draft' => 'array', 'is_active' => 'boolean', 'sort_order' => 'integer',
            'configuration_version' => 'integer', 'crop_variety_id' => 'integer', 'sowing_method_id' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CropFieldLayoutTranslation::class);
    }

    public function crops(): BelongsToMany
    {
        return $this->belongsToMany(Crop::class, 'crop_field_layout_crops');
    }

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }

    public function sowingMethod(): BelongsTo
    {
        return $this->belongsTo(SowingMethod::class);
    }

    public function translation(): ?CropFieldLayoutTranslation
    {
        if (! $this->relationLoaded('translations')) {
            return null;
        }

        return $this->translations->firstWhere('locale', app()->getLocale())
            ?? $this->translations->firstWhere('locale', config('languages.default'));
    }
}

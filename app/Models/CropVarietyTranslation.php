<?php

namespace App\Models;

use Database\Factories\CropVarietyTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropVarietyTranslation extends Model
{
    /** @use HasFactory<CropVarietyTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description', 'region_notes'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function cropVariety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class);
    }
}

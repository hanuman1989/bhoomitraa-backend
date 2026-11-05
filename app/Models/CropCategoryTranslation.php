<?php

namespace App\Models;

use Database\Factories\CropCategoryTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropCategoryTranslation extends Model
{
    /** @use HasFactory<CropCategoryTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function cropCategory(): BelongsTo
    {
        return $this->belongsTo(CropCategory::class);
    }
}

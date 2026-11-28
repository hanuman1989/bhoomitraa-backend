<?php

namespace App\Models;

use Database\Factories\CropFieldLayoutTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropFieldLayoutTranslation extends Model
{
    /** @use HasFactory<CropFieldLayoutTranslationFactory> */
    use HasFactory;

    protected $fillable = ['locale', 'title', 'description', 'important_considerations'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function cropFieldLayout(): BelongsTo
    {
        return $this->belongsTo(CropFieldLayout::class);
    }
}

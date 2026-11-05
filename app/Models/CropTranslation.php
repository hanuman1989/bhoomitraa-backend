<?php

namespace App\Models;

use Database\Factories\CropTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropTranslation extends Model
{
    /** @use HasFactory<CropTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'name', 'description'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }
}

<?php

namespace App\Models;

use Database\Factories\SowingMethodTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SowingMethodTranslation extends Model
{
    /** @use HasFactory<SowingMethodTranslationFactory> */
    use HasFactory;

    protected $fillable = ['locale', ...SowingMethod::TRANSLATED_FIELDS];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['sowing_method_id' => 'integer'];
    }

    public function sowingMethod(): BelongsTo
    {
        return $this->belongsTo(SowingMethod::class);
    }
}

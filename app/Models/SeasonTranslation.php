<?php

namespace App\Models;

use Database\Factories\SeasonTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonTranslation extends Model
{
    /** @use HasFactory<SeasonTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['season_id', 'locale', 'name', 'description'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }
}

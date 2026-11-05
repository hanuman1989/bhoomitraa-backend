<?php

namespace App\Models;

use Database\Factories\RegionTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionTranslation extends Model
{
    /** @use HasFactory<RegionTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'name'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}

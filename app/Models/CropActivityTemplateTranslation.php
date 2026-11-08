<?php

namespace App\Models;

use Database\Factories\CropActivityTemplateTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropActivityTemplateTranslation extends Model
{
    /** @use HasFactory<CropActivityTemplateTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['locale', 'title', 'description', 'instructions'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function cropActivityTemplate(): BelongsTo
    {
        return $this->belongsTo(CropActivityTemplate::class);
    }
}

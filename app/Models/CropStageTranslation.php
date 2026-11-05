<?php

namespace App\Models;

use Database\Factories\CropStageTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CropStageTranslation extends Model
{
    /** @use HasFactory<CropStageTranslationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['crop_stage_id', 'locale', 'name', 'description'];

    public function cropStage(): BelongsTo
    {
        return $this->belongsTo(CropStage::class);
    }
}

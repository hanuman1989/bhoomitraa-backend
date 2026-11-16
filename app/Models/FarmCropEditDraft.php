<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmCropEditDraft extends Model
{
    protected $fillable = ['draft_data', 'wizard_progress', 'base_revision', 'revision'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['draft_data' => 'array', 'wizard_progress' => 'array', 'base_revision' => 'integer', 'revision' => 'integer'];
    }

    public function farmCropCycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class);
    }
}

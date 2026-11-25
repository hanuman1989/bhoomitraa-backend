<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmCropActivityNote extends Model
{
    protected $fillable = ['farm_crop_activity_id', 'user_id', 'note', 'note_date'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['farm_crop_activity_id' => 'integer', 'user_id' => 'integer', 'note_date' => 'immutable_date:Y-m-d'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(FarmCropActivity::class, 'farm_crop_activity_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmCropSoilAssessment extends Model
{
    protected $fillable = ['fertile_capacity', 'soil_moisture', 'drainage_condition', 'water_availability', 'assessment_source', 'assessed_at', 'notes'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['assessed_at' => 'immutable_datetime'];
    }

    public function farmCropCycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class);
    }
}

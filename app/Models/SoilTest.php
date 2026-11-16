<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SoilTest extends Model
{
    public const MEASUREMENTS = ['soil_ph', 'electrical_conductivity', 'organic_carbon', 'nitrogen', 'phosphorus', 'potassium', 'sulphur', 'zinc', 'iron', 'copper', 'manganese', 'boron'];

    protected $fillable = ['test_date', 'sample_collected_at', 'laboratory_name', 'report_number', 'sample_depth_cm', ...self::MEASUREMENTS, 'measurement_units', 'notes'];

    protected $hidden = ['report_file', 'verified_by'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['test_date' => 'immutable_date:Y-m-d', 'sample_collected_at' => 'immutable_date:Y-m-d',
            'measurement_units' => 'array', ...array_fill_keys(self::MEASUREMENTS, 'decimal:4')];
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function farmCropCycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class);
    }
}

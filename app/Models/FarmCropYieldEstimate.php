<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmCropYieldEstimate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['yield_benchmark_id', 'estimate_type', 'estimated_min_yield', 'estimated_avg_yield', 'estimated_max_yield',
        'yield_unit', 'cultivated_area', 'area_unit', 'estimated_at', 'model_version', 'input_snapshot', 'notes'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['yield_benchmark_id' => 'integer', 'estimated_min_yield' => 'decimal:4', 'estimated_avg_yield' => 'decimal:4', 'estimated_max_yield' => 'decimal:4',
            'cultivated_area' => 'decimal:8', 'estimated_at' => 'immutable_datetime', 'input_snapshot' => 'array'];
    }

    public function farmCropCycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class);
    }

    public function benchmark(): BelongsTo
    {
        return $this->belongsTo(CropYieldBenchmark::class, 'yield_benchmark_id');
    }
}

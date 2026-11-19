<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmCropFertilizerPlan extends Model
{
    protected $fillable = ['fertilizer_id', 'custom_name', 'fertilizer_recommendation_id', 'quantity_mode', 'recommended_quantity', 'planned_quantity', 'quantity_unit_code',
        'fertilizer_price_id', 'package_quantity', 'package_unit_code', 'package_count', 'package_price', 'estimated_cost', 'currency_code', 'calculation_snapshot', 'notes'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_id' => 'integer', 'fertilizer_recommendation_id' => 'integer', 'fertilizer_price_id' => 'integer',
            'recommended_quantity' => 'decimal:4', 'planned_quantity' => 'decimal:4', 'package_quantity' => 'decimal:4', 'package_count' => 'integer',
            'package_price' => 'decimal:4', 'estimated_cost' => 'decimal:4', 'calculation_snapshot' => 'array'];
    }

    public function farmCropCycle(): BelongsTo
    {
        return $this->belongsTo(FarmCropCycle::class);
    }

    public function fertilizer(): BelongsTo
    {
        return $this->belongsTo(Fertilizer::class);
    }

    public function recommendation(): BelongsTo
    {
        return $this->belongsTo(FertilizerRecommendation::class, 'fertilizer_recommendation_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(FertilizerPrice::class, 'fertilizer_price_id');
    }
}

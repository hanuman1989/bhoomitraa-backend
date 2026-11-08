<?php

namespace App\Models;

use Database\Factories\FertilizerRecommendationNutrientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FertilizerRecommendationNutrient extends Model
{
    /** @use HasFactory<FertilizerRecommendationNutrientFactory> */
    use HasFactory;

    public const CODES = FertilizerNutrient::CODES;

    /**
     * The initial dose contract supports only the shared kilogram/hectare codes.
     *
     * @return list<string>
     */
    public static function unitCodes(): array
    {
        return array_values(array_intersect(Fertilizer::UNIT_CODES, ['kg']));
    }

    /** @return list<string> */
    public static function areaUnitCodes(): array
    {
        return array_values(array_intersect(CropSeedRate::AREA_UNIT_CODES, ['ha']));
    }

    protected $fillable = ['nutrient_code', 'recommended_quantity', 'unit_code', 'area_unit_code'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['fertilizer_recommendation_id' => 'integer', 'recommended_quantity' => 'decimal:4'];
    }

    public function fertilizerRecommendation(): BelongsTo
    {
        return $this->belongsTo(FertilizerRecommendation::class);
    }
}

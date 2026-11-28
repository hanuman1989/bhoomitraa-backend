<?php

namespace App\Enums;

/**
 * Water availability of an irrigation source, as observed by the farmer. Farms keep
 * their own, different water_availability list in config/farms.php.
 */
enum IrrigationWaterAvailability: string
{
    case Good = 'good';
    case Moderate = 'moderate';
    case Low = 'low';
    case Seasonal = 'seasonal';
    case NoWater = 'no_water';
    case Unknown = 'unknown';

    public function label(?string $locale = null): string
    {
        return __('irrigation.water_availability.'.$this->value, [], $locale);
    }
}

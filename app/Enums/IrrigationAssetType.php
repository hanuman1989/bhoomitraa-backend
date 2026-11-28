<?php

namespace App\Enums;

/** The category of an irrigation asset. It cannot change after creation. */
enum IrrigationAssetType: string
{
    case Source = 'source';
    case Pipeline = 'pipeline';

    public function label(?string $locale = null): string
    {
        return __('irrigation.asset_types.'.$this->value, [], $locale);
    }
}

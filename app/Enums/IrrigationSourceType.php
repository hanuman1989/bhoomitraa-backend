<?php

namespace App\Enums;

enum IrrigationSourceType: string
{
    case Borewell = 'borewell';
    case OpenWell = 'open_well';
    case Canal = 'canal';
    case Pond = 'pond';
    case Tank = 'tank';
    case River = 'river';
    case Reservoir = 'reservoir';
    case RainwaterCollection = 'rainwater_collection';
    case Other = 'other';

    /** Depth is the reported physical depth of the structure, not the current water level. */
    public function supportsDepth(): bool
    {
        return in_array($this, [self::Borewell, self::OpenWell, self::Pond, self::Reservoir], true);
    }

    public function label(?string $locale = null): string
    {
        return __('irrigation.source_types.'.$this->value, [], $locale);
    }
}

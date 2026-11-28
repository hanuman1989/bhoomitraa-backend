<?php

namespace App\Enums;

/** Inactive assets stay listed; deleting is separate. */
enum IrrigationAssetStatus: string
{
    case Operational = 'operational';
    case NeedsMaintenance = 'needs_maintenance';
    case OutOfService = 'out_of_service';
    case Inactive = 'inactive';

    public function label(?string $locale = null): string
    {
        return __('irrigation.statuses.'.$this->value, [], $locale);
    }
}

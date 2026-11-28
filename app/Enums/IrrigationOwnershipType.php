<?php

namespace App\Enums;

enum IrrigationOwnershipType: string
{
    case Owned = 'owned';
    case Shared = 'shared';
    case Rented = 'rented';
    case Public = 'public';
    case Other = 'other';

    public function label(?string $locale = null): string
    {
        return __('irrigation.ownership_types.'.$this->value, [], $locale);
    }
}

<?php

namespace App\Enums;

enum IrrigationPipelineType: string
{
    case Underground = 'underground';
    case Surface = 'surface';
    case Portable = 'portable';
    case Other = 'other';

    public function label(?string $locale = null): string
    {
        return __('irrigation.pipeline_types.'.$this->value, [], $locale);
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RiskIndicatorDirection: string implements HasLabel
{
    case HigherIsWorse = 'higher_is_worse';
    case LowerIsWorse = 'lower_is_worse';

    public function getLabel(): string
    {
        return match ($this) {
            self::HigherIsWorse => __('Higher values are worse'),
            self::LowerIsWorse => __('Lower values are worse'),
        };
    }
}

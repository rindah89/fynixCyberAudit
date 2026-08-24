<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TechnologyExposureState: string implements HasLabel
{
    case WithinAppetite = 'within_appetite';
    case AboveAppetite = 'above_appetite';

    public function getLabel(): string
    {
        return match ($this) {
            self::WithinAppetite => __('Within appetite'),
            self::AboveAppetite => __('Above appetite'),
        };
    }
}

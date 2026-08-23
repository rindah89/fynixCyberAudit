<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ControlTestMetricType: string implements HasLabel
{
    case Boolean = 'boolean';
    case Numeric = 'numeric';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Boolean => __('Boolean'),
            self::Numeric => __('Numeric'),
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RiskIndicatorStatus: string implements HasLabel
{
    case Normal = 'normal';
    case Warning = 'warning';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Normal => __('Normal'),
            self::Warning => __('Warning'),
            self::Critical => __('Critical'),
        };
    }
}

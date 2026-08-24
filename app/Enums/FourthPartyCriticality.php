<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FourthPartyCriticality: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'warning',
            self::High, self::Critical => 'danger',
        };
    }
}

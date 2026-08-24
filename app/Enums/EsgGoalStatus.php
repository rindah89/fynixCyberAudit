<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgGoalStatus: string implements HasColor, HasLabel
{
    case Active = 'Active';
    case Achieved = 'Achieved';
    case AtRisk = 'At Risk';
    case Retired = 'Retired';

    public function getLabel(): ?string
    {
        return $this->value;
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'info',
            self::Achieved => 'success',
            self::AtRisk => 'warning',
            self::Retired => 'gray',
        };
    }
}

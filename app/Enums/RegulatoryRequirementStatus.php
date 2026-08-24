<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RegulatoryRequirementStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Superseded = 'superseded';
    case Repealed = 'repealed';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success', self::Superseded => 'warning', self::Repealed => 'danger',
        };
    }
}

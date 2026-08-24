<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RegulatorySourceStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return $this === self::Active ? 'success' : 'gray';
    }
}

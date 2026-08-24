<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FourthPartyDependencyStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Exited = 'exited';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return $this === self::Active ? 'success' : 'gray';
    }
}

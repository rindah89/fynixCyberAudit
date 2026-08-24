<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EsgKpiDirection: string implements HasLabel
{
    case Increase = 'increase';
    case Decrease = 'decrease';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Increase => 'Increase toward target',
            self::Decrease => 'Decrease toward target',
        };
    }
}

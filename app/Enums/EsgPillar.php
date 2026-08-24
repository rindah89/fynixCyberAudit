<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgPillar: string implements HasColor, HasLabel
{
    case Environmental = 'Environmental';
    case Social = 'Social';
    case Governance = 'Governance';

    public function getLabel(): ?string
    {
        return $this->value;
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Environmental => 'success',self::Social => 'info',self::Governance => 'warning'
        };
    }
}

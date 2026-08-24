<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ModelLifecycleStatus: string implements HasColor, HasLabel
{
    case Proposed = 'Proposed';
    case Development = 'Development';
    case Production = 'Production';
    case Retired = 'Retired';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'info', self::Development => 'warning', self::Production => 'success', self::Retired => 'gray'
        };
    }
}

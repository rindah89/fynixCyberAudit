<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgKpiStatus: string implements HasColor, HasLabel
{
    case TargetMet = 'target_met';
    case TargetNotMet = 'target_not_met';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::TargetMet => 'Target met',
            self::TargetNotMet => 'Target not met',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::TargetMet => 'success',
            self::TargetNotMet => 'warning',
        };
    }
}

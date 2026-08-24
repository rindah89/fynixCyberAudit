<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditManagementPosition: string implements HasColor, HasLabel
{
    case Agreed = 'agreed';
    case PartiallyAgreed = 'partially_agreed';
    case Disagreed = 'disagreed';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Agreed => 'success', self::PartiallyAgreed => 'warning', self::Disagreed => 'danger',
        };
    }
}

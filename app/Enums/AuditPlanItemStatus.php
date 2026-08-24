<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditPlanItemStatus: string implements HasColor, HasLabel
{
    case Planned = 'planned';
    case Scheduled = 'scheduled';
    case Deferred = 'deferred';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Planned => 'info', self::Scheduled => 'warning', self::Deferred => 'gray'
        };
    }
}

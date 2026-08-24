<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditPlanStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }

    public function getColor(): string
    {
        return $this === self::Approved ? 'success' : 'info';
    }
}

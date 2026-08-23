<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecoveryPlanStatus: string implements HasLabel
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Retired = 'retired';

    public function getLabel(): ?string
    {
        return __(ucfirst($this->value));
    }
}

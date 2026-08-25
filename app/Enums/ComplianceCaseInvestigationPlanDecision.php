<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseInvestigationPlanDecision: string implements HasColor, HasLabel
{
    case Approved = 'Approved';
    case Rejected = 'Rejected';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return $this === self::Approved ? 'success' : 'danger';
    }
}

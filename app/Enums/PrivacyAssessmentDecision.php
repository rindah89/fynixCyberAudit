<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrivacyAssessmentDecision: string implements HasColor, HasLabel
{
    case Approved = 'Approved';
    case MitigationRequired = 'Mitigation Required';
    case Rejected = 'Rejected';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success', self::MitigationRequired => 'warning', self::Rejected => 'danger'
        };
    }
}

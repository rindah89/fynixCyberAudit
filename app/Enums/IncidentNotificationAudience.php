<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IncidentNotificationAudience: string implements HasLabel
{
    case Regulator = 'Regulator';
    case AffectedIndividuals = 'Affected Individuals';
    case Partner = 'Partner';
    case Insurer = 'Insurer';
    case LawEnforcement = 'Law Enforcement';
    case Other = 'Other';

    public function getLabel(): string
    {
        return __($this->value);
    }
}

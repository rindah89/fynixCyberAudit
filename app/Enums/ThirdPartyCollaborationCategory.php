<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyCollaborationCategory: string implements HasLabel
{
    case Assurance = 'assurance';
    case Evidence = 'evidence';
    case Risk = 'risk';
    case Contract = 'contract';
    case Incident = 'incident';
    case Resilience = 'resilience';
    case Onboarding = 'onboarding';
    case Offboarding = 'offboarding';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }
}

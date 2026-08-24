<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyOnboardingCategory: string implements HasLabel
{
    case Security = 'security';
    case Privacy = 'privacy';
    case Resilience = 'resilience';
    case Compliance = 'compliance';
    case Access = 'access';
    case Operational = 'operational';
    case Financial = 'financial';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(ucfirst($this->value));
    }
}

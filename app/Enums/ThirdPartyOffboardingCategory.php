<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyOffboardingCategory: string implements HasLabel
{
    case Access = 'access';
    case Data = 'data';
    case Asset = 'asset';
    case Knowledge = 'knowledge';
    case Continuity = 'continuity';
    case Financial = 'financial';
    case Compliance = 'compliance';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }
}

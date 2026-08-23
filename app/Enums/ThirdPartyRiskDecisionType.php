<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyRiskDecisionType: string implements HasLabel
{
    case Approved = 'approved';
    case ConditionallyApproved = 'conditionally_approved';
    case Rejected = 'rejected';
    case Terminated = 'terminated';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RiskGovernanceDecision: string implements HasLabel
{
    case Accepted = 'accepted';
    case Mitigate = 'mitigate';
    case Transfer = 'transfer';
    case Avoid = 'avoid';

    public function getLabel(): string
    {
        return __(str($this->value)->title()->toString());
    }
}

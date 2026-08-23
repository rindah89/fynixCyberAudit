<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiGovernanceDecisionType: string implements HasLabel
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequired = 'changes_required';
    case Suspended = 'suspended';

    public function getLabel(): ?string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyRiskReviewOutcome: string implements HasLabel
{
    case Satisfactory = 'satisfactory';
    case NeedsAction = 'needs_action';
    case Terminate = 'terminate';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}

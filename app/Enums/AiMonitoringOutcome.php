<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiMonitoringOutcome: string implements HasLabel
{
    case Satisfactory = 'satisfactory';
    case NeedsAction = 'needs_action';
    case Suspended = 'suspended';

    public function getLabel(): ?string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}

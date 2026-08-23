<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiLifecycleStatus: string implements HasLabel
{
    case Proposed = 'proposed';
    case Pilot = 'pilot';
    case Active = 'active';
    case Suspended = 'suspended';
    case Retired = 'retired';

    public function getLabel(): ?string
    {
        return __(ucfirst($this->value));
    }
}

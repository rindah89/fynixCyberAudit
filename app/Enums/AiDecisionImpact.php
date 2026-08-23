<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AiDecisionImpact: string implements HasLabel
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function getLabel(): ?string
    {
        return __(ucfirst($this->value));
    }
}

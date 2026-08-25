<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseIntakeDecision: string implements HasColor, HasLabel
{
    case Accepted = 'Accepted';
    case Rejected = 'Rejected';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Accepted => 'success', self::Rejected => 'danger',
        };
    }
}

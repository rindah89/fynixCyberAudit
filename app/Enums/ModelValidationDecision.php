<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ModelValidationDecision: string implements HasColor, HasLabel
{
    case Approved = 'Approved';
    case ConditionallyApproved = 'Conditionally Approved';
    case ChangesRequired = 'Changes Required';
    case Rejected = 'Rejected';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success', self::ConditionallyApproved, self::ChangesRequired => 'warning', self::Rejected => 'danger'
        };
    }
}

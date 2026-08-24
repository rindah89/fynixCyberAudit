<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ModelGovernanceStatus: string implements HasColor, HasLabel
{
    case ValidationRequired = 'Validation Required';
    case ValidationExpired = 'Validation Expired';
    case Approved = 'Approved';
    case Restricted = 'Restricted';
    case Rejected = 'Rejected';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ValidationRequired, self::ValidationExpired => 'warning', self::Approved => 'success', self::Restricted => 'warning', self::Rejected => 'danger'
        };
    }
}

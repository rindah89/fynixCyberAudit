<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyContractDecision: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case ConditionallyApproved = 'conditionally_approved';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => __('Approved'),
            self::ConditionallyApproved => __('Conditionally Approved'),
            self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success', self::ConditionallyApproved => 'warning', self::Rejected => 'danger',
        };
    }
}

<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PolicyExceptionDecisionType: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case Denied = 'denied';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => __('Approved'),
            self::Denied => __('Denied'),
            self::Revoked => __('Revoked'),
        };
    }

    public function getColor(): string
    {
        return $this === self::Approved ? 'success' : 'danger';
    }
}

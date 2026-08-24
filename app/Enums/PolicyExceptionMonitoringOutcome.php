<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PolicyExceptionMonitoringOutcome: string implements HasColor, HasLabel
{
    case Effective = 'effective';
    case NeedsAction = 'needs_action';
    case RevokeRecommended = 'revoke_recommended';

    public function getLabel(): string
    {
        return match ($this) {
            self::Effective => __('Effective'),
            self::NeedsAction => __('Needs action'),
            self::RevokeRecommended => __('Revoke recommended'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Effective => 'success',
            self::NeedsAction => 'warning',
            self::RevokeRecommended => 'danger',
        };
    }
}

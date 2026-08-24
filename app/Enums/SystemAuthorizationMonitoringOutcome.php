<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SystemAuthorizationMonitoringOutcome: string implements HasColor, HasLabel
{
    case Effective = 'effective';
    case NeedsAction = 'needs_action';
    case RevocationRecommended = 'revocation_recommended';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Effective => 'Effective',self::NeedsAction => 'Needs action',self::RevocationRecommended => 'Revocation recommended'
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Effective => 'success',self::NeedsAction => 'warning',self::RevocationRecommended => 'danger'
        };
    }
}

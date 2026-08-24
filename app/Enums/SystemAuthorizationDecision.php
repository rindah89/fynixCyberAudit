<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SystemAuthorizationDecision: string implements HasColor, HasLabel
{
    case Authorized = 'authorized';
    case AuthorizedWithConditions = 'authorized_with_conditions';
    case Denied = 'denied';
    case Revoked = 'revoked';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Authorized => 'Authorized', self::AuthorizedWithConditions => 'Authorized with conditions', self::Denied => 'Denied', self::Revoked => 'Revoked'
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Authorized => 'success', self::AuthorizedWithConditions => 'warning', self::Denied, self::Revoked => 'danger'
        };
    }
}

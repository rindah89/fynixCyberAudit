<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PolicyExceptionStatus: string implements HasColor, HasIcon, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Expired = 'expired';
    case Revoked = 'revoked';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Pending'),
            self::Approved => __('Approved'),
            self::Denied => __('Denied'),
            self::Expired => __('Expired'),
            self::Revoked => __('Revoked'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Denied => 'danger',
            self::Expired => 'gray',
            self::Revoked => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::Pending => 'heroicon-o-clock',
            self::Approved => 'heroicon-o-check-circle',
            self::Denied => 'heroicon-o-x-circle',
            self::Expired => 'heroicon-o-calendar',
            self::Revoked => 'heroicon-o-no-symbol',
        };
    }
}
